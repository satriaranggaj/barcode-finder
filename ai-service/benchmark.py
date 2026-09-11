"""Offline CPU benchmark. No database writes or production requests.

Manifest: {"references":[{"path":"...","sku":"..."}],
           "queries":[{"path":"...","sku":"...","case":"hand"}]}
Relative image paths are resolved relative to the manifest, not the working dir.
Use distinct held-out query photos for a meaningful SKU accuracy benchmark.
"""
import argparse
import json
import platform
from pathlib import Path
from time import perf_counter
import statistics

parser = argparse.ArgumentParser()
parser.add_argument('manifest', type=Path)
parser.add_argument('--output', type=Path, required=True)
args = parser.parse_args()
manifest = json.loads(args.manifest.read_text(encoding='utf-8-sig'))
if not 1 <= len(manifest['references']) <= 1000 or not 1 <= len(manifest['queries']) <= 1000:
    parser.error('Offline benchmark supports 1..1000 references and queries; use pgvector for scale tests.')

started = perf_counter()
from app.main import extract
from app.model import model
startup = perf_counter()-started
try:
    import psutil
    process = psutil.Process()
except ImportError:
    process = None

result = {'dataset_note': manifest.get('dataset_note', 'User-labelled held-out dataset; verify labels.'),
          'environment': {'platform': platform.platform(), 'processor': platform.processor(),
                          'startup_s': startup, 'model_bytes': sum(p.numel()*p.element_size() for p in model.parameters())},
          'references': [], 'queries': []}
# Warm-up separately so steady-state timing does not include lazy kernel setup.
first = (args.manifest.parent / manifest['references'][0]['path']).resolve()
extract(first.read_bytes(), True)
for group in ('references', 'queries'):
    for index, entry in enumerate(manifest[group]):
        path = (args.manifest.parent / entry['path']).resolve()
        if path.stat().st_size > 20*1024*1024:
            raise ValueError('benchmark image exceeds upload limit')
        contents = path.read_bytes()
        row = {'sku': str(entry['sku']), 'case': entry.get('case', 'unspecified')}
        # Alternate order to reduce systematic warm-cache bias.
        for mode in (('old', 'new') if index % 2 == 0 else ('new', 'old')):
            row[mode] = extract(contents, mode == 'old')
        result[group].append(row)
        print(f'{group} {index+1}/{len(manifest[group])}', flush=True)

result['environment']['rss_mib_after'] = process.memory_info().rss/1048576 if process else None
if process:
    result['environment']['peak_working_set_mib'] = getattr(process.memory_info(), 'peak_wset', 0)/1048576 or None
result['latency_ms'] = {}
for mode in ('old', 'new'):
    times = [row[mode]['timings'] for row in result['queries']]
    result['latency_ms'][mode] = {key: {'median': statistics.median([x[key] for x in times]),
        'max': max(x[key] for x in times)} for key in times[0]}
    if mode == 'new':
        values = [row[mode]['preprocessing']['preprocessing_ms'] for row in result['queries']]
        result['latency_ms'][mode]['preprocessing_ms'] = {'median': statistics.median(values), 'max': max(values)}
args.output.parent.mkdir(parents=True, exist_ok=True)
args.output.write_text(json.dumps(result, allow_nan=False), encoding='utf-8')
print(json.dumps({'environment': result['environment'], 'latency_ms': result['latency_ms']}, indent=2))
