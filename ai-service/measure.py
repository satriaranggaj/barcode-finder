"""Separate local measurement tool; normal image search never invokes this."""
import argparse
import json
import os
from pathlib import Path
from PIL import Image, ImageOps
from app.preprocessing import decode, MAX_BYTES
from app.measurement import measure

parser = argparse.ArgumentParser()
parser.add_argument('image', type=Path)
parser.add_argument('--marker-cm', type=float, required=True)
parser.add_argument('--marker-id', type=int, default=0)
parser.add_argument('--corners', required=True, help='JSON [[x,y],...] with four ordered object corners in upright image pixels')
parser.add_argument('--coplanar', action='store_true', help='Confirm marker and measured surface lie on the same plane')
args = parser.parse_args()
try:
    if args.image.stat().st_size > MAX_BYTES:
        raise ValueError('image_size_limit')
    with Image.open(args.image) as header:
        budget = int(os.environ.get('AI_DECODE_BUDGET_MB', '384')) * 1024 * 1024
        if header.width * header.height * 12 + args.image.stat().st_size * 2 > budget:
            raise ValueError('measurement_decode_budget')
    image, _ = decode(args.image.read_bytes(), legacy=True)
    image = ImageOps.exif_transpose(image)
    print(json.dumps(measure(image, args.marker_cm, json.loads(args.corners), args.coplanar, args.marker_id)))
except (ValueError, OSError) as error:
    parser.exit(1, str(error)+'\n')
