"""HNSW global indexes + SQLite local vectors. Only trusted local snapshots are loaded."""
from pathlib import Path
from contextlib import closing
import hashlib
import json
import sqlite3
import faiss
import numpy as np
from ..encoders.base import normalize


def file_hash(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open('rb') as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b''):
            digest.update(chunk)
    return digest.hexdigest()


class FaissIndexManager:
    """Single-writer builder, immutable serving snapshot; vector rows retain full metadata.

    FAISS row IDs equal reference IDs for each model's global index. SQLite stores
    every global/local vector separately so reranking never loads the entire corpus.
    """
    def __init__(self, database: Path, signature: dict, create: bool = True):
        database.parent.mkdir(parents=True, exist_ok=True)
        self.db = sqlite3.connect(database, check_same_thread=False)
        self.db.row_factory = sqlite3.Row
        self.signature = signature
        self.indexes: dict = {}
        self.readonly = not create
        if create:
            self.db.executescript('''
                CREATE TABLE IF NOT EXISTS refs (
                    id INTEGER PRIMARY KEY, image_id TEXT UNIQUE NOT NULL,
                    product_id TEXT, sku TEXT NOT NULL, category TEXT,
                    sub_category TEXT, family TEXT, photo_hash TEXT NOT NULL);
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

    def close(self) -> None:
        self.db.close()

    def has_photo_hash(self, digest: str) -> bool:
        return self.db.execute('SELECT 1 FROM refs WHERE photo_hash=? LIMIT 1', (digest,)).fetchone() is not None

    def existing(self, image_id: str):
        row = self.db.execute('SELECT * FROM refs WHERE image_id=?', (image_id,)).fetchone()
        return dict(row) if row else None

    def add_product_embedding(self, metadata: dict, representations: dict) -> int:
        if self.readonly:
            raise ValueError('Serving snapshots are immutable; build a new generation')
        if self.existing(metadata['image_id']):
            raise ValueError('image_id exists; changed images require --rebuild')
        if set(representations) != set(self.signature['models']):
            raise ValueError('All indexed models are required for each reference')
        prepared = {}
        for model, crops in representations.items():
            if set(crops) != {'global', 'center', 'left', 'right', 'top', 'bottom'}:
                raise ValueError('Incomplete crop representations')
            prepared[model] = {name: normalize(vector) for name, vector in crops.items()}
            dimension = prepared[model]['global'].size
            if any(vector.shape != (dimension,) for vector in prepared[model].values()):
                raise ValueError('Invalid embedding dimensions')
            if model in self.indexes and self.indexes[model].d != dimension:
                raise ValueError('Model dimension changed')
        reference_id = self.count
        with self.db:
            self.db.execute('INSERT INTO refs VALUES (?,?,?,?,?,?,?,?)', (reference_id,
                metadata['image_id'], metadata.get('product_id'), metadata['sku'],
                metadata.get('category'), metadata.get('sub_category'), metadata.get('family'),
                metadata['photo_hash']))
            for model, crops in prepared.items():
                for crop, vector in crops.items():
                    self.db.execute('INSERT INTO vectors(ref_id,embedding_type,crop_type,dimension,value) VALUES(?,?,?,?,?)',
                                    (reference_id, model, crop, vector.size, vector.tobytes()))
        for model, crops in prepared.items():
            if model not in self.indexes:
                index = faiss.IndexHNSWFlat(crops['global'].size, 32, faiss.METRIC_INNER_PRODUCT)
                index.hnsw.efConstruction = 200
                self.indexes[model] = index
            self.indexes[model].add(crops['global'][None, :])
        self.count += 1
        return reference_id

    def search(self, model: str, vector: np.ndarray, limit: int, category: str | None = None) -> list[tuple[int, float]]:
        if not self.count or model not in self.indexes:
            return []
        params = faiss.SearchParametersHNSW()
        params.efSearch = max(128, limit * 2)
        if category is not None:
            ids = np.array([r[0] for r in self.db.execute('SELECT id FROM refs WHERE category=?', (category,))], dtype=np.int64)
            if not ids.size:
                return []
            params.sel = faiss.IDSelectorBatch(ids)
        query = normalize(vector).reshape(1, -1)
        if query.shape[1] != self.indexes[model].d:
            raise ValueError('Query/index dimensions do not match')
        scores, ids = self.indexes[model].search(query, min(limit, self.count), params=params)
        return [(int(i), float(s)) for i, s in zip(ids[0], scores[0]) if i >= 0]

    def reference(self, reference_id: int) -> tuple[dict, dict]:
        metadata = dict(self.db.execute('SELECT * FROM refs WHERE id=?', (reference_id,)).fetchone())
        representations = {}
        for row in self.db.execute('SELECT * FROM vectors WHERE ref_id=?', (reference_id,)):
            representations.setdefault(row['embedding_type'], {})[row['crop_type']] = np.frombuffer(row['value'], dtype=np.float32)
        return metadata, representations

    def save_index(self, directory: Path) -> None:
        """Write a new generation. Caller atomically switches CURRENT after success."""
        if any(index.ntotal != self.count for index in self.indexes.values()):
            raise ValueError('Incomplete in-memory index; discard this build')
        directory.mkdir(parents=True, exist_ok=False)
        with closing(sqlite3.connect(directory / 'metadata.sqlite')) as target:
            self.db.backup(target)
        for name, index in self.indexes.items():
            faiss.write_index(index, str(directory / f'{name}.faiss'))
        files = {p.name: file_hash(p) for p in directory.iterdir() if p.is_file()}
        manifest = {'version': 1, 'signature': self.signature, 'count': self.count,
                    'dimensions': {name: idx.d for name, idx in self.indexes.items()}, 'files': files}
        (directory / 'manifest.json').write_text(json.dumps(manifest, indent=2), encoding='utf-8')

    @classmethod
    def load_index(cls, directory: Path) -> 'FaissIndexManager':
        manifest = json.loads((directory / 'manifest.json').read_text(encoding='utf-8'))
        if manifest.get('version') != 1:
            raise ValueError('Unsupported index version')
        if set(manifest['dimensions']) - {'siglip', 'dino'}:
            raise ValueError('Unsupported index models')
        required = {'metadata.sqlite'} | {f'{name}.faiss' for name in manifest['dimensions']}
        if set(manifest['files']) != required:
            raise ValueError('Incomplete snapshot')
        for name, digest in manifest['files'].items():
            if file_hash(directory / name) != digest:
                raise ValueError('Snapshot checksum mismatch')
        manager = cls(directory / 'metadata.sqlite', manifest['signature'], create=False)
        try:
            manager.db.execute('PRAGMA query_only=ON')
            if manager.count != manifest['count']:
                raise ValueError('Metadata/index count mismatch')
            for name, dimension in manifest['dimensions'].items():
                idx = faiss.read_index(str(directory / f'{name}.faiss'))
                if idx.ntotal != manager.count or idx.d != dimension or idx.metric_type != faiss.METRIC_INNER_PRODUCT:
                    raise ValueError('FAISS index metadata mismatch')
                manager.indexes[name] = idx
            if manager.count and set(manager.indexes) != set(manager.signature['models']):
                raise ValueError('Missing model index')
            vector_count = manager.db.execute('SELECT count(*) FROM vectors').fetchone()[0]
            if vector_count != manager.count * len(manager.indexes) * 6:
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
