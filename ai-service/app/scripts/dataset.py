"""SKU folder names remain strings, preserving leading zeros."""
from pathlib import Path
import json
from ..preprocessing.pipeline import decode_image


def iter_images(root: Path):
    if not root.is_dir():
        raise ValueError(f'Dataset directory does not exist: {root}')
    for folder in sorted(root.iterdir()):
        if folder.is_dir() and not folder.is_symlink():
            for path in sorted(folder.iterdir()):
                if path.is_file() and not path.is_symlink() and path.suffix.lower() in ('.jpg', '.jpeg', '.png', '.webp'):
                    yield folder.name, path


def read_photo(path: Path, settings):
    with path.open('rb') as file:
        return decode_image(file.read(settings.max_bytes + 1), settings.max_bytes, settings.max_pixels, settings.processing_memory_mb)


def load_metadata(root: Path) -> dict:
    path = root / 'metadata.json'
    result = json.loads(path.read_text(encoding='utf-8')) if path.exists() else {}
    if not isinstance(result, dict):
        raise ValueError('metadata.json must map SKU strings to metadata objects')
    for sku, row in result.items():
        if not isinstance(row, dict):
            raise ValueError(f'Invalid metadata for {sku}')
        for field in ('category', 'sub_category', 'family'):
            if row.get(field) is not None and not isinstance(row[field], str):
                raise ValueError(f'{field} must be a string')
        if row.get('product_id') is not None and not isinstance(row['product_id'], (int, str)):
            raise ValueError('product_id must be an integer or string')
    return result
