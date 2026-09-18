"""Object-centric HNSW indexes + SQLite local vectors. Only trusted local snapshots are loaded.

Each model owns two FAISS indexes: an 'object' index holding selected-object
vectors (only references with a real crop) and a 'global' index holding the
full-image context vector of every reference. Manifest version 2 binds both;
older or partial snapshots are rejected on load.
"""
from pathlib import Path
from contextlib import closing, nullcontext
import hashlib
import json
import sqlite3
import faiss
import numpy as np
from ..encoders.base import normalize

REPRESENTATIONS = ('object', 'global')


def file_hash(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open('rb') as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b''):
            digest.update(chunk)
    return digest.hexdigest()


class FaissIndexManager:
    """Single-writer builder, immutable serving snapshot; vector rows retain full metadata.

    Global FAISS row IDs equal reference IDs. Object FAISS rows are dense over
    only the selected references and must be mapped back to reference IDs.
    SQLite stores every global/local vector separately so reranking never loads
    the entire corpus. refs.representation is 'object' when the reference has a
    real selected-object crop, else 'full'.
    """
    def __init__(self, database: Path, signature: dict, create: bool = True):
        database.parent.mkdir(parents=True, exist_ok=True)
        self.db = sqlite3.connect(database, check_same_thread=False)
        self.db.row_factory = sqlite3.Row
        self.signature = signature
        self.indexes: dict[str, dict[str, faiss.Index]] = {}
        self.readonly = not create
        if create:
            self.db.executescript('''
                CREATE TABLE IF NOT EXISTS refs (
                    id INTEGER PRIMARY KEY, image_id TEXT UNIQUE NOT NULL,
                    product_id TEXT, sku TEXT NOT NULL, category TEXT,
                    sub_category TEXT, family TEXT, photo_hash TEXT NOT NULL,
                    representation TEXT NOT NULL DEFAULT 'full',
                    selection_source TEXT,
                    extra TEXT NOT NULL DEFAULT '{}');
                CREATE INDEX IF NOT EXISTS category_idx ON refs(category);
                CREATE INDEX IF NOT EXISTS hash_idx ON refs(photo_hash);
                CREATE TABLE IF NOT EXISTS vectors (
                    vector_id INTEGER PRIMARY KEY,
                    ref_id INTEGER NOT NULL REFERENCES refs(id),
                    embedding_type TEXT NOT NULL, crop_type TEXT NOT NULL,
                    dimension INTEGER NOT NULL, value BLOB NOT NULL,
                    UNIQUE(ref_id, embedding_type, crop_type));
            ''')
        self.count = self.db.execute('SELECT count(*) FROM refs').fetchone()[0]
        # Small identity maps, not vector loads: O(number of object references)
        # once per snapshot, O(1) mapping per retrieved hit/category member.
        self.object_ids = [row[0] for row in self.db.execute("SELECT id FROM refs WHERE representation='object' ORDER BY id")]
        self.object_positions = {ref_id: position for position, ref_id in enumerate(self.object_ids)}

    @property
    def object_count(self) -> int:
        return self.db.execute("SELECT count(*) FROM refs WHERE representation='object'").fetchone()[0]

    def close(self) -> None:
        self.db.close()

    def has_photo_hash(self, digest: str) -> bool:
        return self.db.execute('SELECT 1 FROM refs WHERE photo_hash=? LIMIT 1', (digest,)).fetchone() is not None

    def existing(self, image_id: str):
        row = self.db.execute('SELECT * FROM refs WHERE image_id=?', (image_id,)).fetchone()
        return dict(row) if row else None

    def skus(self, reference_ids: list[int]) -> dict[int, str]:
        """SKU per reference id, used to cap shortlist slots per SKU (anti-count-bias)."""
        if not reference_ids:
            return {}
        placeholders = ','.join('?' * len(reference_ids))
        rows = self.db.execute(f'SELECT id, sku FROM refs WHERE id IN ({placeholders})', reference_ids).fetchall()
        return {row['id']: row['sku'] for row in rows}

    def add_product_embedding(self, metadata: dict, representations: dict) -> int:
        if self.readonly:
            raise ValueError('Serving snapshots are immutable; build a new generation')
        if self.existing(metadata['image_id']):
            raise ValueError('image_id exists; changed images require --rebuild')
        if set(representations) != set(self.signature['models']):
            raise ValueError('All indexed models are required for each reference')
        representation = metadata.get('representation', 'full')
        if representation not in ('object', 'full'):
            raise ValueError('Invalid reference representation')
        prepared = {}
        for model, crops in representations.items():
            required = {'global', 'center', 'left', 'right', 'top', 'bottom', 'context'}
            if model == 'siglip' and self.signature['preprocessing'].get('description_text'):
                required.add('description')
            if model == 'dino' and self.signature['preprocessing'].get('patches', 0):
                required.add('patches')
            if set(crops) != required:
                raise ValueError('Incomplete crop representations')
            prepared[model] = {name: normalize(vector) for name, vector in crops.items()}
            dimension = prepared[model]['global'].size
            if any(vector.shape != (dimension,) for key, vector in prepared[model].items() if key != 'patches'):
                raise ValueError('Invalid embedding dimensions')
            if 'patches' in prepared[model]:
                patches = prepared[model]['patches']
                if patches.ndim != 2 or patches.shape[1] != dimension or not 4 <= len(patches) <= self.signature['preprocessing']['patches']:
                    raise ValueError('Invalid patch shape')
            if model in self.indexes and self.indexes[model]['global'].d != dimension:
                raise ValueError('Model dimension changed')
        reference_id = self.count
        # Builders may own a bounded batch transaction; standalone inserts still commit.
        with (nullcontext() if self.db.in_transaction else self.db):
            self.db.execute('INSERT INTO refs VALUES (?,?,?,?,?,?,?,?,?,?,?)', (reference_id,
                metadata['image_id'], metadata.get('product_id'), metadata['sku'],
                metadata.get('category'), metadata.get('sub_category'), metadata.get('family'),
                metadata['photo_hash'], representation, metadata.get('selection_source'),
                metadata.get('extra', '{}')))
            for model, crops in prepared.items():
                for crop, vector in crops.items():
                    self.db.execute('INSERT INTO vectors(ref_id,embedding_type,crop_type,dimension,value) VALUES(?,?,?,?,?)',
                                    (reference_id, model, crop, vector.size, vector.tobytes()))
        for model, crops in prepared.items():
            if model not in self.indexes:
                self.indexes[model] = {rep: faiss.IndexHNSWFlat(crops['global'].size, 32, faiss.METRIC_INNER_PRODUCT)
                                       for rep in REPRESENTATIONS}
                for index in self.indexes[model].values():
                    index.hnsw.efConstruction = 200
            # Global index always carries the full-image (context) vector; the
            # object index only carries true selected-object vectors.
            self.indexes[model]['global'].add(crops['context'][None, :])
            if representation == 'object':
                self.indexes[model]['object'].add(crops['global'][None, :])
        if representation == 'object':
            self.object_positions[reference_id] = len(self.object_ids)
            self.object_ids.append(reference_id)
        self.count += 1
        return reference_id

    def search(self, model: str, vector: np.ndarray, limit: int, category: str | None = None,
               representation: str = 'global') -> list[tuple[int, float]]:
        if model not in self.indexes or representation not in self.indexes[model]:
            return []
        index = self.indexes[model][representation]
        if not index.ntotal:
            return []
        params = faiss.SearchParametersHNSW()
        params.efSearch = max(128, limit * 2)
        if category is not None:
            reference_ids = [r[0] for r in self.db.execute('SELECT id FROM refs WHERE category=?', (category,))]
            ids = np.array([self.object_positions[i] for i in reference_ids if i in self.object_positions]
                           if representation == 'object' else reference_ids, dtype=np.int64)
            if not ids.size:
                return []
            params.sel = faiss.IDSelectorBatch(ids)
        query = normalize(vector).reshape(1, -1)
        if query.shape[1] != index.d:
            raise ValueError('Query/index dimensions do not match')
        scores, ids = index.search(query, min(limit, index.ntotal), params=params)
        return [(self.object_ids[int(i)] if representation == 'object' else int(i), float(s))
                for i, s in zip(ids[0], scores[0]) if i >= 0]

    def reference(self, reference_id: int) -> tuple[dict, dict]:
        metadata = dict(self.db.execute('SELECT * FROM refs WHERE id=?', (reference_id,)).fetchone())
        representations = {}
        for row in self.db.execute('SELECT * FROM vectors WHERE ref_id=?', (reference_id,)):
            vector = np.frombuffer(row['value'], dtype=np.float32)
            if row['crop_type'] == 'patches':
                vector = vector.reshape(-1, self.indexes[row['embedding_type']]['global'].d)
            representations.setdefault(row['embedding_type'], {})[row['crop_type']] = vector
        return metadata, representations

    def references(self, reference_ids: list[int]) -> dict[int, tuple[dict, dict]]:
        """Bulk-load metadata + local vectors for a bounded shortlist only.

        Two queries total (refs, vectors) instead of two per reference; callers
        must never pass a full-corpus id list.
        """
        if not reference_ids:
            return {}
        placeholders = ','.join('?' * len(reference_ids))
        by_id = {row['id']: (dict(row), {}) for row in
                 self.db.execute(f'SELECT * FROM refs WHERE id IN ({placeholders})', reference_ids)}
        for row in self.db.execute(f'SELECT * FROM vectors WHERE ref_id IN ({placeholders})', reference_ids):
            metadata, representations = by_id[row['ref_id']]
            vector = np.frombuffer(row['value'], dtype=np.float32)
            if row['crop_type'] == 'patches':
                vector = vector.reshape(-1, self.indexes[row['embedding_type']]['global'].d)
            representations.setdefault(row['embedding_type'], {})[row['crop_type']] = vector
        return by_id

    def save_index(self, directory: Path) -> None:
        """Write a new generation. The caller verifies it before switching CURRENT."""
        object_count = self.object_count
        for indexes in self.indexes.values():
            if indexes['global'].ntotal != self.count or indexes['object'].ntotal != object_count:
                raise ValueError('Incomplete in-memory index; discard this build')
        directory.mkdir(parents=True, exist_ok=False)
        with closing(sqlite3.connect(directory / 'metadata.sqlite')) as target:
            self.db.backup(target)
        for model, indexes in self.indexes.items():
            for representation, index in indexes.items():
                faiss.write_index(index, str(directory / f'{model}.{representation}.faiss'))
        files = {p.name: file_hash(p) for p in directory.iterdir() if p.is_file()}
        manifest = {'version': 2, 'signature': self.signature, 'count': self.count,
                    'object_count': object_count,
                    'dimensions': {name: {rep: idx.d for rep, idx in indexes.items()}
                                   for name, indexes in self.indexes.items()},
                    'files': files}
        (directory / 'manifest.json').write_text(json.dumps(manifest, indent=2), encoding='utf-8')

    @classmethod
    def load_index(cls, directory: Path) -> 'FaissIndexManager':
        manifest = json.loads((directory / 'manifest.json').read_text(encoding='utf-8'))
        if manifest.get('version') != 2:
            raise ValueError('Unsupported index version; rebuild the index')
        dimensions = manifest.get('dimensions', {})
        if set(dimensions) - {'siglip', 'dino'} or any(set(representations) != set(REPRESENTATIONS)
                                                       for representations in dimensions.values()):
            raise ValueError('Unsupported index models/representations')
        required = {'metadata.sqlite'} | {f'{name}.{rep}.faiss' for name, reps in dimensions.items() for rep in reps}
        if set(manifest['files']) != required:
            raise ValueError('Incomplete snapshot')
        for name, digest in manifest['files'].items():
            if file_hash(directory / name) != digest:
                raise ValueError('Snapshot checksum mismatch')
        manager = cls(directory / 'metadata.sqlite', manifest['signature'], create=False)
        manager.fingerprint = hashlib.sha256(json.dumps(manifest['files'], sort_keys=True).encode()).hexdigest()
        try:
            manager.db.execute('PRAGMA query_only=ON')
            if manager.count != manifest['count']:
                raise ValueError('Metadata/index count mismatch')
            object_count = manifest.get('object_count')
            if not isinstance(object_count, int) or not 0 <= object_count <= manager.count:
                raise ValueError('Invalid object count')
            if object_count != len(manager.object_ids):
                raise ValueError('Object identity mapping/count mismatch')
            for name, representations in dimensions.items():
                for rep, dimension in representations.items():
                    expected = object_count if rep == 'object' else manager.count
                    idx = faiss.read_index(str(directory / f'{name}.{rep}.faiss'))
                    if idx.ntotal != expected or idx.d != dimension or idx.metric_type != faiss.METRIC_INNER_PRODUCT:
                        raise ValueError('FAISS index metadata mismatch')
                    manager.indexes.setdefault(name, {})[rep] = idx
            if manager.count and set(manager.indexes) != set(manager.signature['models']):
                raise ValueError('Missing model index')
            vector_count = manager.db.execute('SELECT count(*) FROM vectors').fetchone()[0]
            per_ref = len(manager.indexes) * 7 + int(bool(manager.signature['preprocessing'].get('patches', 0)))
            per_ref += int(bool(manager.signature['preprocessing'].get('description_text')))
            if vector_count != manager.count * per_ref:
                raise ValueError('Incomplete crop metadata')
            return manager
        except Exception:
            manager.close()
            raise


def current_generation(root: Path) -> Path:
    name = (root / 'CURRENT').read_text(encoding='utf-8').strip()
    if not name.startswith('generation-') or Path(name).name != name or '/' in name or '\\' in name:
        raise ValueError('Invalid index generation')
    return root / name
