"""Best-effort retention for published FAISS generations and stale build dirs.

Runs after a generation is atomically published, while the builder lock is
still held. Never touches CURRENT, never deletes anything that is not a
strictly valid generation/build directory inside the index root, and never
raises: every failure is logged and serving/indexing continue untouched.
"""
import json
import logging
import os
import re
import shutil
import time
from pathlib import Path

logger = logging.getLogger(__name__)

# Strictly valid published generations: 'generation-' + 32 lowercase hex
# (uuid4().hex as written by build_index.save/publish path).
GENERATION_PATTERN = re.compile(r'^generation-[0-9a-f]{32}$')

# Temporary build workspaces: tempfile prefix used by build_index.
BUILD_PREFIX = 'build-'

# Persisted supersession timestamps: generation -> unix time when it stopped
# being CURRENT. Written atomically after each publication (writer holds the
# builder lock); read by cleanup on both sides. Survives process restarts.
RETENTION_STATE = '.retention.json'


def read_current(root: Path) -> str | None:
    """Published generation name, or None when CURRENT is missing/corrupt."""
    try:
        name = (root / 'CURRENT').read_text(encoding='utf-8').strip()
    except (OSError, ValueError):
        return None
    return name if GENERATION_PATTERN.match(name) else None


def _safe_child(root: Path, name: str) -> Path | None:
    """Validated child directory: plain name, not a symlink, resolves inside root."""
    if name in ('.', '..') or '/' in name or '\\' in name:
        return None
    target = root / name
    try:
        if target.is_symlink() or not target.is_dir():
            return None
        if target.resolve().parent != root.resolve():
            return None
    except OSError:
        return None
    return target


def directory_size(path: Path) -> int:
    """Recursive byte size; symlinks never followed, errors yield partial sum."""
    total = 0
    try:
        with os.scandir(path) as entries:
            for entry in entries:
                try:
                    if entry.is_symlink():
                        continue
                    if entry.is_dir(follow_symlinks=False):
                        total += directory_size(Path(entry.path))
                    else:
                        total += entry.stat(follow_symlinks=False).st_size
                except OSError:
                    continue
    except OSError:
        pass
    return total


def _remove_tree(path: Path) -> bool:
    try:
        shutil.rmtree(path)
        return not path.exists()
    except Exception:
        logger.warning('Index cleanup: failed to remove %s', path, exc_info=True)
        return False


def read_superseded(root: Path) -> dict:
    """Supersession timestamps {generation: unix_ts}; corrupt/missing -> {}."""
    try:
        raw = (root / RETENTION_STATE).read_text(encoding='utf-8')
        data = json.loads(raw)
        superseded = data.get('superseded') if isinstance(data, dict) else None
        if not isinstance(superseded, dict):
            return {}
        return {name: float(ts) for name, ts in superseded.items()
                if GENERATION_PATTERN.match(name) and isinstance(ts, (int, float))}
    except (OSError, ValueError):
        return {}


def record_superseded(root: Path | str, previous: str | None) -> None:
    """Stamp when `previous` stopped being CURRENT. Atomic, best-effort.

    Called right after publication while the builder lock is held. Prunes
    entries for generations no longer on disk so the file stays small.
    Never raises.
    """
    try:
        root = Path(root)
        state_file = root / RETENTION_STATE
        mapping = read_superseded(root)
        try:
            present = set(os.listdir(root))
        except OSError:
            present = set()
        mapping = {name: ts for name, ts in mapping.items() if name in present}
        if previous is not None and GENERATION_PATTERN.match(previous):
            mapping[previous] = time.time()
        tmp = root / (RETENTION_STATE + '.tmp')
        tmp.write_text(json.dumps({'superseded': mapping}, sort_keys=True), encoding='utf-8')
        os.replace(tmp, state_file)
    except Exception:
        logger.warning('Index cleanup: failed to record supersession', exc_info=True)


def _pid_alive(pid: int) -> bool:
    try:
        os.kill(pid, 0)
        return True
    except (OSError, ValueError, OverflowError):
        return False


def _build_pruning_allowed(root: Path, own_pid: int | None) -> bool:
    """True only when no *other* live build could own a build-* workspace.

    The caller passing its own PID (post-publish cleanup inside its own
    lifecycle) always passes: its active workspace is additionally protected
    by the staleness age gate below. A lock held by another live process
    blocks pruning; a stale/unparseable lock does not.
    """
    lock_path = root / 'build.lock'
    try:
        if not lock_path.is_file() or lock_path.is_symlink():
            return True
        content = lock_path.read_text(encoding='utf-8').strip()
    except OSError:
        return True
    if own_pid is not None and content == str(own_pid):
        return True
    try:
        pid = int(content)
    except ValueError:
        return True
    if _pid_alive(pid):
        logger.info('Index cleanup: build.lock held by live pid %d; skipping build-* pruning', pid)
        return False
    return True


def cleanup_index_root(root: Path | str, *, keep: int = 3, grace_seconds: int = 3600,
                        build_stale_hours: int = 24, own_pid: int | None = None) -> dict:
    """Remove superseded generations and stale build dirs. Never raises.

    Keeps CURRENT always, plus the newest `keep` generations (keep<=0 keeps
    all), plus anything within `grace_seconds` of being superseded (persisted
    supersession timestamps, mtime fallback) for lazy hot reload and
    in-flight requests. Returns a summary dict with names and freed MB.
    """
    root = Path(root)
    summary = {'current': None, 'kept': [], 'removed': [], 'freed_mb': 0.0,
               'stale_builds_removed': 0, 'stale_builds_freed_mb': 0.0,
               'builds_skipped': False}
    try:
        if not root.is_dir() or root.is_symlink():
            return summary
        current = read_current(root)
        summary['current'] = current
        if current is None:
            # Missing/corrupt CURRENT: fail closed, delete nothing.
            logger.warning('Index cleanup: CURRENT missing or corrupt; removing nothing')
            return summary
        now = time.time()
        superseded = read_superseded(root)
        generations = []
        stale_builds = []
        try:
            names = os.listdir(root)
        except OSError:
            logger.warning('Index cleanup: cannot list %s', root, exc_info=True)
            return summary
        for name in names:
            if GENERATION_PATTERN.match(name):
                target = _safe_child(root, name)
                if target is None:
                    continue
                try:
                    generations.append((target.stat().st_mtime, name))
                except OSError:
                    continue
            elif name.startswith(BUILD_PREFIX):
                if _safe_child(root, name) is not None:
                    stale_builds.append(name)
        generations.sort(key=lambda item: (item[0], item[1]), reverse=True)
        keep_names = {current}
        if keep <= 0:
            keep_names.update(name for _, name in generations)
        else:
            keep_names.update(name for _, name in generations[:keep])
        for mtime, name in generations:
            # Grace runs from supersession, not creation: a generation served
            # for days then superseded seconds ago must survive. mtime is only
            # the fallback when no supersession record exists.
            ref_ts = max(mtime, superseded.get(name, float('-inf')))
            if now - ref_ts < grace_seconds:
                keep_names.add(name)
        for _, name in generations:
            if name in keep_names:
                summary['kept'].append(name)
                continue
            # Revalidate immediately before deleting: CURRENT may have moved
            # since planning (manual cleanup runs without our lock).
            if read_current(root) == name:
                summary['kept'].append(name)
                continue
            target = _safe_child(root, name)
            if target is None:
                continue
            freed = directory_size(target)
            if _remove_tree(target):
                summary['removed'].append(name)
                summary['freed_mb'] += freed / (1024 * 1024)
        if build_stale_hours > 0 and _build_pruning_allowed(root, own_pid):
            cutoff = now - build_stale_hours * 3600
            for name in sorted(stale_builds):
                target = _safe_child(root, name)
                if target is None:
                    continue
                try:
                    if target.stat().st_mtime > cutoff:
                        continue
                except OSError:
                    continue
                freed = directory_size(target)
                if _remove_tree(target):
                    summary['stale_builds_removed'] += 1
                    summary['stale_builds_freed_mb'] += freed / (1024 * 1024)
        elif build_stale_hours > 0:
            summary['builds_skipped'] = True
        # Drop supersession records for generations no longer on disk.
        try:
            present = set(os.listdir(root))
            trimmed = {name: ts for name, ts in superseded.items() if name in present}
            if trimmed != superseded:
                tmp = root / (RETENTION_STATE + '.tmp')
                tmp.write_text(json.dumps({'superseded': trimmed}, sort_keys=True), encoding='utf-8')
                os.replace(tmp, root / RETENTION_STATE)
        except Exception:
            logger.warning('Index cleanup: failed to trim retention state', exc_info=True)
        summary['freed_mb'] = round(summary['freed_mb'], 1)
        summary['stale_builds_freed_mb'] = round(summary['stale_builds_freed_mb'], 1)
        logger.info('Index cleanup: current=%s kept=%d removed=%d freed_mb=%s '
                    'stale_builds=%d stale_builds_mb=%s',
                    current, len(summary['kept']), len(summary['removed']),
                    summary['freed_mb'], summary['stale_builds_removed'],
                    summary['stale_builds_freed_mb'])
        if summary['removed']:
            logger.debug('Index cleanup removed: %s', ', '.join(summary['removed']))
        return summary
    except Exception:
        logger.exception('Index cleanup: unexpected failure; serving continues')
        return summary
