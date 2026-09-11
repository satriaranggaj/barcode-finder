"""Compare bounded preprocessing sizes on a labelled manifest (no embeddings)."""
import argparse
import json
import statistics
from pathlib import Path
from time import perf_counter
from PIL import Image
import app.preprocessing as preprocessing

parser = argparse.ArgumentParser()
parser.add_argument('manifest', type=Path)
parser.add_argument('--output', type=Path, required=True)
args = parser.parse_args()
data = json.loads(args.manifest.read_text(encoding='utf-8-sig'))
if not 1 <= len(data['references']) <= 1000:
    parser.error('Use 1..1000 references')
preprocessing.cv2.setNumThreads(1)
report = {}
for size in (256,384,512):
    preprocessing.WORK_SIZE = size  # Benchmark process only; never a runtime setting.
    times, modes = [], {}
    for entry in data['references']:
        path = (args.manifest.parent / entry['path']).resolve()
        with path.open('rb') as stream:
            contents = stream.read(preprocessing.MAX_BYTES+1)
        image, _ = preprocessing.decode(contents)
        start = perf_counter()
        result = preprocessing.prepare(image)
        times.append((perf_counter()-start)*1000)
        mode = result.metadata['mode']
        modes[mode] = modes.get(mode,0)+1
        image.close()
    report[size] = {'median_ms': statistics.median(times), 'max_ms': max(times), 'modes': modes}
args.output.write_text(json.dumps(report,indent=2),encoding='utf-8')
