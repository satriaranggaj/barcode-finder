"""Measure the stateless second descriptor request, without embedding or network."""
import argparse
import json
from pathlib import Path
from time import perf_counter
from app.preprocessing import decode, cv2
from app.conditional_preprocessing import prepare
from app.descriptors import describe

parser = argparse.ArgumentParser()
parser.add_argument('manifest', type=Path)
parser.add_argument('--output', type=Path, required=True)
args = parser.parse_args()
manifest = json.loads(args.manifest.read_text(encoding='utf-8-sig'))
cv2.setNumThreads(1)
result = {}
for split in ('tuning', 'evaluation'):
    result[split] = []
    for row in manifest[split]:
        contents = (args.manifest.parent / row['path']).read_bytes()
        samples = {}
        for signal in ('shape', 'texture', 'local', 'color', 'proportion'):
            samples[signal] = []
            for _ in range(3):
                started = perf_counter()
                image, _ = decode(contents)
                prepared = prepare(image)
                describe(prepared, {signal})
                samples[signal].append((perf_counter() - started) * 1000)
                image.close()
        result[split].append({'photo_id': row['photo_id'], 'samples_ms': samples})
args.output.write_text(json.dumps(result), encoding='utf-8')
