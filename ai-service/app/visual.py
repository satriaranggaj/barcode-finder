"""Shared, bounded query/reference representation; display images stay intact."""
import hashlib
import io
import os
import warnings
from pathlib import Path
from functools import lru_cache
import numpy as np
from PIL import Image, ImageOps
import cv2

MODEL_SHA = '309c8469258dda742793dce0ebea8e6dd393174f89934733ecc8b14c76f4ddd8'
VERSION = 'lensku-object-1'
MAX_BYTES = 20 * 1024 * 1024
cv2.setNumThreads(1)


def decode(contents):
    if not contents or len(contents) > MAX_BYTES:
        raise ValueError('image_size_limit')
    with warnings.catch_warnings():
        warnings.simplefilter('error', Image.DecompressionBombWarning)
        with Image.open(io.BytesIO(contents)) as probe:
            if probe.format not in ('JPEG', 'PNG', 'WEBP') or getattr(probe, 'n_frames', 1) != 1:
                raise ValueError('unsupported_image')
            if probe.width * probe.height > 80_000_000:
                raise ValueError('pixel_limit')
            probe.verify()
        with Image.open(io.BytesIO(contents)) as source:
            source.draft('RGB', (1024, 1024))
            if source.width * source.height * 8 > 256 * 1024 * 1024:
                raise ValueError('decode_memory_limit')
            source.load()
            oriented = ImageOps.exif_transpose(source)
            if 'A' in oriented.getbands():
                oriented = Image.alpha_composite(Image.new('RGBA', oriented.size, (127,127,127,255)), oriented.convert('RGBA'))
            return oriented.convert('RGB')


@lru_cache(maxsize=1)
def segmenter():
    import onnxruntime as ort
    path = Path(os.environ.get('OBJECT_MODEL_PATH', 'models/u2netp.onnx'))
    if hashlib.sha256(path.read_bytes()).hexdigest() != MODEL_SHA:
        raise RuntimeError('Object model checksum mismatch')
    options = ort.SessionOptions()
    options.intra_op_num_threads = 2
    options.inter_op_num_threads = 1
    return ort.InferenceSession(str(path), options, providers=['CPUExecutionProvider'])


def focus(image, session=None):
    session = session or segmenter()
    working = image.copy()
    working.thumbnail((512,512), Image.Resampling.LANCZOS)
    small = np.asarray(working.resize((320,320), Image.Resampling.LANCZOS), dtype=np.float32)
    small /= max(1, float(small.max()))
    small = (small-np.array([.485,.456,.406], np.float32))/np.array([.229,.224,.225], np.float32)
    output = session.run(None, {session.get_inputs()[0].name: small.transpose(2,0,1)[None]})[0][0,0]
    if not np.isfinite(output).all():
        raise RuntimeError('Invalid segmentation response')
    span = float(output.max()-output.min())
    probability = (output-output.min())/span if span > 1e-6 else np.zeros_like(output)
    probability = cv2.resize(probability, working.size, interpolation=cv2.INTER_LINEAR)
    mask = (probability >= .5).astype(np.uint8)
    meta = {'focus': 'full_frame', 'hand_separation': 'not_guaranteed'}
    if .02 < float(mask.mean()) < .95:
        ys, xs = np.where(mask)
        pad = max(4, round(max(np.ptp(xs),np.ptp(ys))*.1))
        left, top = max(0,int(xs.min())-pad), max(0,int(ys.min())-pad)
        right, bottom = min(working.width,int(xs.max())+pad+1), min(working.height,int(ys.max())+pad+1)
        # Soft suppression and all foreground components preserve uncertain edges.
        alpha = .15+.85*probability[...,None]
        rgb = np.clip(np.asarray(working)*alpha+127*(1-alpha),0,255).astype(np.uint8)
        working = Image.fromarray(rgb[top:bottom,left:right])
        mask = mask[top:bottom,left:right]
        meta['focus'] = 'foreground'
    else:
        mask = None
    side = max(working.size)
    canvas = Image.new('RGB',(side,side),(127,127,127))
    offset = ((side-working.width)//2,(side-working.height)//2)
    canvas.paste(working,offset)
    if mask is not None:
        padded = np.zeros((side,side),np.uint8)
        padded[offset[1]:offset[1]+working.height,offset[0]:offset[0]+working.width] = mask
        mask = padded
    return canvas, mask, meta


def histogram(values):
    total = float(values.sum())
    return (values/total).tolist() if total else None


def descriptors(image, mask):
    rgb = np.asarray(image.resize((256,256),Image.Resampling.LANCZOS))
    selected = np.ones((256,256),bool) if mask is None else cv2.resize(mask,(256,256),interpolation=cv2.INTER_NEAREST)>0
    hsv = cv2.cvtColor(rgb,cv2.COLOR_RGB2HSV)
    color = histogram(np.histogramdd(hsv[selected,:2],bins=(8,4),range=((0,180),(0,256)))[0].ravel())
    gray = cv2.cvtColor(rgb,cv2.COLOR_RGB2GRAY)
    magnitude, angle = cv2.cartToPolar(cv2.Sobel(gray,cv2.CV_32F,1,0),cv2.Sobel(gray,cv2.CV_32F,0,1))
    pattern = []
    for y in (0,128):
        for x in (0,128):
            valid = selected[y:y+128,x:x+128]
            pattern.extend(np.histogram(angle[y:y+128,x:x+128][valid]%np.pi,bins=8,range=(0,np.pi),weights=magnitude[y:y+128,x:x+128][valid])[0])
    lbp = np.zeros((254,254),np.uint8)
    for bit,(dy,dx) in enumerate(((-1,-1),(-1,0),(-1,1),(0,1),(1,1),(1,0),(1,-1),(0,-1))):
        lbp |= (gray[1+dy:255+dy,1+dx:255+dx]>=gray[1:255,1:255]).astype(np.uint8)<<bit
    texture = histogram(np.histogram(lbp[selected[1:255,1:255]],bins=16,range=(0,256))[0])
    shape = None
    if mask is not None:
        points = np.column_stack(np.where(selected)).astype(np.float32)
        _,(width,height),_ = cv2.minAreaRect(points)
        shape = [min(width,height)/max(width,height,1),float(selected.mean())]
    return {'color':color,'texture':texture,'pattern':histogram(np.asarray(pattern)),'shape':shape}
