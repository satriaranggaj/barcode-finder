"""Build immutable generations; incomplete runs never replace the serving snapshot."""
import argparse
from contextlib import closing
import logging
import os
from pathlib import Path
import shutil
import sqlite3
import tempfile
import uuid
import json
import faiss
from ..config import Settings
from ..preprocessing.pipeline import photo_hash
from ..search.service import RetrievalService, load_encoders, is_object_box
from ..search.faiss_index import FaissIndexManager, current_generation, file_hash
from .dataset import iter_images, load_metadata, read_photo
from ..preprocessing.selection import BoundingBox
from ..features.attributes import parse_attributes, VERSION as ATTRIBUTE_VERSION


def build(dataset: Path, root: Path, service: RetrievalService, rebuild: bool = False, reference_limit_per_sku: int | None = None, use_selection: bool = True) -> dict:
    """Incremental append; changed/deleted references require explicit --rebuild.

    Lock prevents concurrent publication. Old generations remain available for
    running readers and rollback; remove manually only after workers stop.
    """
    root.mkdir(parents=True, exist_ok=True)
    if (dataset/'.exporting').exists():
        raise ValueError('Laravel export did not complete; do not publish partial data')
    lock_path = root / 'build.lock'
    with lock_path.open('x') as lock:
        lock.write(str(os.getpid()))
    manager = None
    try:
        metadata = load_metadata(dataset)
        with tempfile.TemporaryDirectory(prefix='build-', dir=root) as workspace:
            try:
                if (root / 'CURRENT').exists() and not rebuild:
                    try:
                        old = FaissIndexManager.load_index(current_generation(root))
                    except ValueError as exc:
                        raise ValueError(f'{exc}; use --rebuild') from exc
                    try:
                        if old.signature != service.signature:
                            raise ValueError('Pipeline/model changed; use --rebuild')
                        database = Path(workspace) / 'metadata.sqlite'
                        with closing(sqlite3.connect(database)) as target:
                            old.db.backup(target)
                        manager = FaissIndexManager(database, service.signature)
                        manager.indexes = {name: {rep: faiss.clone_index(index) for rep, index in indexes.items()}
                                           for name, indexes in old.indexes.items()}
                    finally:
                        old.close()
                else:
                    manager = FaissIndexManager(Path(workspace) / 'metadata.sqlite', service.signature)
                added, skipped = 0, 0
                seen = set()
                sku_counts = {}
                manager.db.execute('BEGIN')
                for sku, path in iter_images(dataset):
                    sku_counts[sku] = sku_counts.get(sku,0)+1
                    if reference_limit_per_sku and sku_counts[sku] > reference_limit_per_sku: continue
                    image_id = path.relative_to(dataset).as_posix()
                    seen.add(image_id)
                    sidecar = path.with_suffix('.json')
                    extra = json.loads(sidecar.read_text(encoding='utf-8')) if sidecar.exists() else {}
                    if not isinstance(extra, dict):
                        raise ValueError('Reference sidecar must be an object')
                    extra['parsed_attributes'] = {'source': 'description_parser', 'version': ATTRIBUTE_VERSION,
                                                  'values': parse_attributes(extra.get('description', ''))}
                    extra['source_file_hash'] = file_hash(path)
                    # Keep input metadata distinct from derived auto-selection.
                    # Otherwise an unchanged photo with no input crop compares
                    # unequal to its persisted auto box on the next append.
                    input_extra = dict(extra)
                    box = BoundingBox(**extra['crop']) if extra.get('crop') else None
                    if box is None and extra.get('selection_source') == 'full':
                        box = BoundingBox(0, 0, 1, 1)
                    if not use_selection: box = None
                    existing = manager.existing(image_id)
                    image = None if existing else read_photo(path, service.settings)
                    digest = existing['photo_hash'] if existing else photo_hash(image)
                    row = {'image_id': image_id, 'sku': sku, 'photo_hash': digest,
                           'extra': json.dumps(extra, sort_keys=True),
                           **{key: extra.get(key, metadata.get(sku, {}).get(key)) for key in ('product_id', 'category', 'sub_category', 'family')}}
                    if existing:
                        stored_extra = json.loads(existing['extra'])
                        comparable_extra = stored_extra.get('_input_metadata', stored_extra)
                        if comparable_extra != input_extra or any(
                                str(existing.get(k)) != str(v) for k, v in row.items() if k != 'extra'):
                            raise ValueError(f'Changed image/metadata: {image_id}; use --rebuild')
                        skipped += 1
                    else:
                        vectors, info = service.embed(image, service.settings.preprocessing_mode, box, extra.get('description') or '')
                        used_box = box
                        if used_box is None and info.get('selection_used'):
                            # Auto proposal actually selected a region: record it so
                            # metadata matches the stored object representation.
                            used_box = BoundingBox(**info['selection_used'])
                            extra['crop'] = info['selection_used']
                            extra.setdefault('selection_source', 'auto')
                            row['extra'] = json.dumps(extra, sort_keys=True)
                        row['representation'] = 'object' if is_object_box(used_box) else 'full'
                        row['selection_source'] = extra.get('selection_source')
                        extra['_input_metadata'] = input_extra
                        row['extra'] = json.dumps(extra, sort_keys=True)
                        manager.add_product_embedding(row, vectors)
                        added += 1
                    if (added + skipped) % 25 == 0:
                        print(f'Processed={added+skipped} Added={added} Skipped={skipped}', flush=True)
                    if (added + skipped) % 100 == 0:
                        manager.db.commit(); manager.db.execute('BEGIN')
                if not seen:
                    raise ValueError('Dataset contains no supported reference images')
                if manager.count != len(seen):
                    raise ValueError('References deleted from dataset; use --rebuild')
                manager.db.commit()
                generation = f'generation-{uuid.uuid4().hex}'
                directory = root / generation
                manager.save_index(directory)
                # A corrupt or partial generation must never replace the published
                # index: verify the snapshot before flipping the CURRENT pointer.
                try:
                    verified = FaissIndexManager.load_index(directory)
                    verified.close()
                except Exception:
                    shutil.rmtree(directory, ignore_errors=True)
                    raise
                pointer = root / 'CURRENT.tmp'
                pointer.write_text(generation, encoding='utf-8')
                os.replace(pointer, root / 'CURRENT')
                return {'added': added, 'skipped': skipped, 'references': len(seen), 'generation': generation}
            finally:
                if manager is not None:
                    manager.close()
    finally:
        lock_path.unlink(missing_ok=True)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--dataset', type=Path, default=Path('dataset'))
    parser.add_argument('--rebuild', action='store_true')
    parser.add_argument('--reference-limit-per-sku', type=int, default=None,
                        help='Cap photos per SKU (anti-count-bias for ablations)')
    args = parser.parse_args()
    logging.basicConfig(level=logging.INFO)
    settings = Settings.from_env()
    faiss.omp_set_num_threads(settings.cpu_threads)
    encoders = load_encoders(settings)
    if set(encoders) != {'siglip', 'dino'}:
        raise RuntimeError('Build requires both models; single-model fallback is for serving only')
    print(build(args.dataset.resolve(), settings.index_path, RetrievalService(settings, encoders),
                args.rebuild, args.reference_limit_per_sku))


if __name__ == '__main__':
    main()
