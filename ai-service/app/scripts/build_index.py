"""Build immutable generations; incomplete runs never replace the serving snapshot."""
import argparse
from contextlib import closing
import logging
import os
from pathlib import Path
import sqlite3
import tempfile
import uuid
import faiss
from ..config import Settings
from ..preprocessing.pipeline import photo_hash
from ..search.service import RetrievalService, load_encoders
from ..search.faiss_index import FaissIndexManager, current_generation
from .dataset import iter_images, load_metadata, read_photo


def build(dataset: Path, root: Path, service: RetrievalService, rebuild: bool = False) -> dict:
    """Incremental append; changed/deleted references require explicit --rebuild.

    Lock prevents concurrent publication. Old generations remain available for
    running readers and rollback; remove manually only after workers stop.
    """
    root.mkdir(parents=True, exist_ok=True)
    lock_path = root / 'build.lock'
    with lock_path.open('x') as lock:
        lock.write(str(os.getpid()))
    manager = None
    try:
        metadata = load_metadata(dataset)
        with tempfile.TemporaryDirectory(prefix='build-', dir=root) as workspace:
            try:
                if (root / 'CURRENT').exists() and not rebuild:
                    old = FaissIndexManager.load_index(current_generation(root))
                    try:
                        if old.signature != service.signature:
                            raise ValueError('Pipeline/model changed; use --rebuild')
                        database = Path(workspace) / 'metadata.sqlite'
                        with closing(sqlite3.connect(database)) as target:
                            old.db.backup(target)
                        manager = FaissIndexManager(database, service.signature)
                        manager.indexes = {name: faiss.clone_index(index) for name, index in old.indexes.items()}
                    finally:
                        old.close()
                else:
                    manager = FaissIndexManager(Path(workspace) / 'metadata.sqlite', service.signature)
                added, skipped = 0, 0
                seen = set()
                for sku, path in iter_images(dataset):
                    image_id = path.relative_to(dataset).as_posix()
                    seen.add(image_id)
                    image = read_photo(path, service.settings)
                    digest = photo_hash(image)
                    row = {'image_id': image_id, 'sku': sku, 'photo_hash': digest,
                           **{key: metadata.get(sku, {}).get(key) for key in ('product_id', 'category', 'sub_category', 'family')}}
                    existing = manager.existing(image_id)
                    if existing:
                        if any(str(existing[k]) != str(v) for k, v in row.items()):
                            raise ValueError(f'Changed image/metadata: {image_id}; use --rebuild')
                        skipped += 1
                    else:
                        vectors, _ = service.embed(image, service.settings.preprocessing_mode)
                        manager.add_product_embedding(row, vectors)
                        added += 1
                    if (added + skipped) % 25 == 0:
                        print(f'Processed={added+skipped} Added={added} Skipped={skipped}', flush=True)
                if not seen:
                    raise ValueError('Dataset contains no supported reference images')
                if manager.count != len(seen):
                    raise ValueError('References deleted from dataset; use --rebuild')
                generation = f'generation-{uuid.uuid4().hex}'
                manager.save_index(root / generation)
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
    args = parser.parse_args()
    logging.basicConfig(level=logging.INFO)
    settings = Settings.from_env()
    faiss.omp_set_num_threads(settings.cpu_threads)
    encoders = load_encoders(settings)
    if set(encoders) != {'siglip', 'dino'}:
        raise RuntimeError('Build requires both models; single-model fallback is for serving only')
    print(build(args.dataset.resolve(), settings.index_path, RetrievalService(settings, encoders), args.rebuild))


if __name__ == '__main__':
    main()
