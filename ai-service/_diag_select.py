"""Temporary diagnostic: detection rate + annotated top proposals.

Not part of the application; deleted after use.
"""
import sys
from pathlib import Path
from PIL import Image, ImageDraw
from app.preprocessing.foreground import GrabCutForeground, ColorPopProposals, ContourProposals, SaliencyProposals
from app.preprocessing.selection import MultiSourceSelector, propose_for_ui

ROOT = Path(sys.argv[1] if len(sys.argv) > 1 else
            r'D:\Directory\Pemrograman\barcodeindentify\storage\app\private\reference-build-002')
OUT = Path(sys.argv[2] if len(sys.argv) > 2 else
           r'D:\Directory\Pemrograman\barcodeindentify\ai-service\_diag_out')
OUT.mkdir(parents=True, exist_ok=True)

detectors = {
    'grabcut': GrabCutForeground(.08),
    'saliency': SaliencyProposals(.08),
    'color_pop': ColorPopProposals(.08),
    'contour': ContourProposals(.08),
}

files = sorted(p for p in ROOT.rglob('*') if p.suffix.lower() in ('.jpg', '.jpeg', '.png', '.webp'))
print(f'images={len(files)}')
counts = {name: 0 for name in detectors}
sources = {}
reasons = {}
for path in files:
    with Image.open(path) as handle:
        image = handle.convert('RGB')
    hits = []
    for name, detector in detectors.items():
        try:
            boxes = detector.boxes(image)
        except Exception as exc:  # noqa: BLE001 - diagnostic
            print(f'  {name} ERROR {path.name}: {exc}')
            boxes = []
        if boxes:
            counts[name] += 1
            hits.append(f'{name}={len(boxes)}')
    ui = propose_for_ui(image, MultiSourceSelector())
    reasons[ui['reason']] = reasons.get(ui['reason'], 0) + 1
    top = ui['candidates'][0] if ui['candidates'] else None
    if top:
        sources[top['source']] = sources.get(top['source'], 0) + 1
    draw = ImageDraw.Draw(image, 'RGBA')
    for index, candidate in enumerate(ui['candidates']):
        box = candidate['box']
        px = [box['x'] * image.width, box['y'] * image.height,
              (box['x'] + box['width']) * image.width, (box['y'] + box['height']) * image.height]
        draw.rectangle(px, outline=(0, 220, 0, 255) if index == 0 else (255, 0, 0, 255), width=8 if index == 0 else 3)
    image.thumbnail((700, 700))
    image.save(OUT / f'{path.parent.name}-{path.stem}.jpg', quality=85)
    if top:
        box = top['box']
        print(f"{path.parent.name}/{path.name} hits=[{', '.join(hits)}] top={top['source']} "
              f"rank={top['rank']} box=({box['x']:.2f},{box['y']:.2f},{box['width']:.2f},{box['height']:.2f})")

print()
for name, value in counts.items():
    print(f'{name}: {value}/{len(files)}')
print(f'top1_sources={sources}')
print(f'reasons={reasons}')
print(f'annotated -> {OUT}')