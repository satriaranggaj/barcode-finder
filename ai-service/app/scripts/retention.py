"""Best-effort retention for published FAISS generations and stale build dirs.

Runs after a generation is atomically published, while the builder lock is
still held. Never touches CURRENT, never deletes anything that is not a
strictly valid generation/build directory inside the index root, and never
raises: every failure is logged and serving/indexing continue untouched.
"""
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


def cleanup_index_root(root: Path | str, *, keep: int = 3, grace_seconds: int = 3600,
                        build_stale_hours: int = 24) -> dict:
    """Remove superseded generations and stale build dirs. Never raises.

    Keeps CURRENT always, plus the newest `keep` generations (keep<=0 keeps
    all), plus anything younger than `grace_seconds` for lazy hot reload and
    in-flight requests. Returns a summary dict with names and freed MB.
    """
    root = Path(root)
    summary = {'current': None, 'kept': [], 'removed': [], 'freed_mb': 0.0,
               'stale_builds_removed': 0, 'stale_builds_freed_mb': 0.0}
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
            if now - mtime < grace_seconds:
                keep_names.add(name)
        for _, name in generations:
            if name in keep_names:
                summary['kept'].append(name)
                continue
            target = _safe_child(root, name)
            if target is None:
                continue
            freed = directory_size(target)
            if _remove_tree(target):
                summary['removed'].append(name)
                summary['freed_mb'] += freed / (1024 * 1024)
        if build_stale_hours > 0:
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
