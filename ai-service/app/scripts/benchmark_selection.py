"""CPU latency benchmark for the modular object selection pipeline.

Synthetic images only; no model inference. Measures the fallback chain cost:
primary GrabCut selector, foreground fallback detector and terminal full-image
path, per scenario.
"""
import argparse
import json
import statistics
import time
from pathlib import Path
import numpy as np
from PIL import Image, ImageDraw
from ..preprocessing.selection import ForegroundSelector, SelectionPipeline


def clean(side=640):
    return Image.new('RGB', (side, side), 'white')


def single_object(side=640):
    image = clean(side)
    ImageDraw.Draw(image).rectangle((int(side*.30), int(side*.30), int(side*.70), int(side*.70)), fill='black')
    return image


def cluttered(side=640):
    image = clean(side)
    draw = ImageDraw.Draw(image)
    rng = np.random.default_rng(7)
    for _ in range(6):
        width, height = int(side*rng.uniform(.08, .18)), int(side*rng.uniform(.08, .18))
        x, y = int(side*rng.uniform(.06, .88)), int(side*rng.uniform(.06, .88))
        draw.rectangle((x, y, x+width, y+height), fill=tuple(int(c) for c in rng.integers(0, 255, 3)))
    noise = rng.integers(0, 20, (side, side, 3), dtype='uint8')
    blended = np.clip(np.asarray(image).astype(int) + noise, 0, 255).astype('uint8')
    return Image.fromarray(blended)


def small_object(side=640):
    image = clean(side)
    ImageDraw.Draw(image).rectangle((side//2-10, side//2-10, side//2+10, side//2+10), fill='black')
    return image


def photo_like(side=640):
    rng = np.random.default_rng(3)
    gradient = np.linspace(90, 200, side, dtype='float32')
    base = np.repeat(gradient[:, None], side, axis=1)[..., None].repeat(3, axis=2)
    noise = rng.normal(0, 18, (side, side, 3))
    image = Image.fromarray(np.clip(base+noise, 0, 255).astype('uint8'))
    draw = ImageDraw.Draw(image)
    draw.rectangle((int(side*.35), int(side*.40), int(side*.65), int(side*.75)), fill=(30, 60, 130))
    return image


SCENARIOS = {'clean': clean, 'single_object': single_object, 'cluttered': cluttered,
             'small_object': small_object, 'photo_like': photo_like}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--runs', type=int, default=20)
    parser.add_argument('--side', type=int, default=640)
    parser.add_argument('--padding', type=float, default=.08)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    if not 1 <= args.runs <= 1000 or not 64 <= args.side <= 4096:
        parser.error('Invalid workload bounds')
    pipeline = SelectionPipeline(ForegroundSelector(padding=args.padding))
    report = {'synthetic': True, 'scope': 'Object selection pipeline only; excludes image decode and model inference',
              'padding': args.padding, 'runs': args.runs, 'scenarios': {}}
    for name, builder in SCENARIOS.items():
        image = builder(args.side)
        latency, boxes, reasons = [], [], []
        for _ in range(args.runs):
            started = time.perf_counter()
            proposal = pipeline.propose(image)
            latency.append((time.perf_counter()-started)*1000)
            boxes.append(len(proposal['boxes']))
            reasons.append(proposal['reason'])
        report['scenarios'][name] = {
            'median_latency_ms': statistics.median(latency),
            'p95_latency_ms': float(np.percentile(latency, 95)),
            'boxes_found': sorted(set(boxes)),
            'reason': sorted(set(reasons))}
    with args.output.open('x', encoding='utf-8') as output:
        json.dump(report, output, indent=2)
    print(json.dumps(report, indent=2))


if __name__ == '__main__':
    main()
