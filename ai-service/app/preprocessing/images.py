"""Full-resolution lossy master and display variants; no metadata survives encoding.

All WebP output is lossy (quality 75..95) even at quality 95; the master is a
compressed re-encode of the oriented, alpha-flattened source, never lossless.
"""
import base64
import numpy as np
from io import BytesIO
from PIL import Image, ImageOps
import struct
import zlib
from .pipeline import InvalidImage, decode_image, flatten_alpha


def verify_png_stream(data):
    """Validate deflate pixels in bounded chunks, without allocating the bitmap.

    Pillow.verify checks chunk CRCs only: a CRC-valid IDAT can still carry
    corrupt compressed pixels. This check also bounds expanded bytes by IHDR.
    """
    width, height, depth, color, _, _, interlace = struct.unpack('>IIBBBBB', data[16:29])
    channels = {0: 1, 2: 3, 3: 1, 4: 2, 6: 4}[color]
    passes = [(0, 0, 1, 1)] if interlace == 0 else [
        (0, 0, 8, 8), (4, 0, 8, 8), (0, 4, 4, 8), (2, 0, 4, 4),
        (0, 2, 2, 4), (1, 0, 2, 2), (0, 1, 1, 2)]
    expected = 0
    for x, y, dx, dy in passes:
        w, h = max(0, (width-x+dx-1)//dx), max(0, (height-y+dy-1)//dy)
        if w and h:
            expected += h * (1 + (w * channels * depth + 7)//8)
    decoder = zlib.decompressobj()
    expanded, pos = 0, 8
    try:
        while pos + 12 <= len(data):
            length = int.from_bytes(data[pos:pos+4], 'big')
            if data[pos+4:pos+8] == b'IDAT':
                pending = data[pos+8:pos+8+length]
                while pending:
                    block = decoder.decompress(pending, 64 * 1024)
                    expanded += len(block)
                    if expanded > expected or decoder.unused_data:
                        raise InvalidImage('Invalid PNG pixel stream')
                    pending = decoder.unconsumed_tail
            pos += length + 12
        if not decoder.eof or expanded != expected:
            raise InvalidImage('Truncated PNG pixels')
    except zlib.error as exc:
        raise InvalidImage('Corrupt PNG pixels') from exc


def strip_private_metadata(data, fmt, orientation):
    """Remove EXIF/GPS/comments without decoding; retain only display orientation."""
    exif = Image.Exif()
    if orientation in range(2, 9): exif[274] = orientation
    safe_exif = exif.tobytes() if exif else b''
    if fmt == 'JPEG':
        result = bytearray(b'\xff\xd8'); pos = 2
        if safe_exif:
            result += b'\xff\xe1' + struct.pack('>H', len(safe_exif)+2) + safe_exif
        while pos < len(data):
            start = pos
            if data[pos] != 255: raise InvalidImage('Invalid JPEG marker')
            while pos < len(data) and data[pos] == 255: pos += 1
            if pos >= len(data): raise InvalidImage('Truncated JPEG')
            marker = data[pos]; pos += 1
            if marker == 218:
                if not data.endswith(b'\xff\xd9'): raise InvalidImage('Truncated JPEG pixels')
                return bytes(result) + data[start:]
            if marker == 217: raise InvalidImage('JPEG has no pixels')
            if pos+2 > len(data): raise InvalidImage('Truncated JPEG segment')
            size = int.from_bytes(data[pos:pos+2], 'big')
            if size < 2 or pos+size > len(data): raise InvalidImage('Invalid JPEG segment')
            if marker not in (225, 237, 254): result += data[start:pos+size]
            pos += size
        raise InvalidImage('Truncated JPEG')
    if fmt == 'PNG':
        result = bytearray(data[:8]); pos = 8
        while pos+12 <= len(data):
            size = int.from_bytes(data[pos:pos+4], 'big'); kind = data[pos+4:pos+8]
            end = pos+12+size
            if end > len(data): raise InvalidImage('Truncated PNG')
            if kind not in (b'eXIf', b'tEXt', b'zTXt', b'iTXt'):
                result += data[pos:end]
            if kind == b'IHDR' and safe_exif:
                chunk = b'eXIf' + safe_exif[6:]
                result += struct.pack('>I', len(chunk)-4) + chunk + struct.pack('>I', zlib.crc32(chunk))
            pos = end
            if kind == b'IEND': return bytes(result)
        raise InvalidImage('Truncated PNG')
    chunks = bytearray(); pos = 12
    while pos+8 <= len(data):
        kind = data[pos:pos+4]; size = int.from_bytes(data[pos+4:pos+8], 'little')
        end = pos+8+size+(size%2)
        if end > len(data): raise InvalidImage('Truncated WebP')
        chunk = bytearray(data[pos:end])
        if kind == b'VP8X':
            chunk[8] &= ~12
            if safe_exif: chunk[8] |= 8
        if kind not in (b'EXIF', b'XMP '): chunks += chunk
        pos = end
    if safe_exif:
        chunks += b'EXIF'+struct.pack('<I',len(safe_exif))+safe_exif+b'\0'*(len(safe_exif)%2)
    return b'RIFF'+struct.pack('<I',len(chunks)+4)+b'WEBP'+chunks


def prepare_upload(data, max_bytes, max_pixels, memory_mb, quality=88, catalog_side=1600, thumbnail_side=400,
                   catalog_quality=83, thumbnail_quality=78):
    if not data or len(data) > max_bytes: raise InvalidImage('Upload exceeds MAX_BYTES')
    try:
        with Image.open(BytesIO(data)) as probe:
            fmt, width, height = probe.format, probe.width, probe.height
            if fmt not in ('JPEG','PNG','WEBP') or getattr(probe,'n_frames',1) != 1:
                raise InvalidImage('Unsupported image')
            probe.verify()
        orientation = 1
        if fmt == 'PNG':
            pos = 8
            while pos+12 <= len(data):
                size = int.from_bytes(data[pos:pos+4],'big')
                if data[pos+4:pos+8] == b'eXIf':
                    exif = Image.Exif(); exif.load(data[pos+8:pos+8+size]); orientation = exif.get(274,1)
                pos += size+12
        else:
            with Image.open(BytesIO(data)) as metadata:
                orientation = metadata.getexif().get(274,1)
        sanitized = strip_private_metadata(data,fmt,orientation)
        # Includes source/orientation/RGB copies, encoder buffers and base64 response.
        # The optimizer budget is deliberately stricter than bounded AI decode.
        status = 'preserved_pixel_limit' if width*height > max_pixels else 'preserved_memory_budget' if width*height*24+len(data)*4+32*1024*1024 > memory_mb*1024*1024 else 'optimized'
        if status != 'optimized':
            # JPEG native draft validation stays within the independent decode budget.
            # PNG validation also checks the pixel stream without a full bitmap.
            if fmt == 'PNG':
                verify_png_stream(data)
            if fmt == 'JPEG':
                with Image.open(BytesIO(data)) as check:
                    check.draft('RGB',(max(1,width//8),max(1,height//8)))
                    if check.width*check.height*12+len(data)*2+16*1024*1024 <= memory_mb*1024*1024:
                        check.load()
            return {'status':status, 'master':base64.b64encode(sanitized).decode('ascii'),
                    'extension':{'JPEG':'jpg','PNG':'png','WEBP':'webp'}[fmt],
                    'width':width, 'height':height, 'metadata_stripped':True}
        source = decode_image(data,max_bytes,max_pixels,memory_mb)
        return {**image_variants(source,quality,catalog_side,thumbnail_side,catalog_quality,thumbnail_quality),
                'status':'optimized', 'extension':'webp', 'metadata_stripped':True}
    except (OSError, ValueError, SyntaxError) as exc:
        raise InvalidImage('Invalid or corrupt upload') from exc
from .pipeline import photo_hash


def image_variants(image: Image.Image, quality: int = 88, catalog_side: int = 1600, thumbnail_side: int = 400,
                   catalog_quality: int = 83, thumbnail_quality: int = 78) -> dict:
    if not all(75 <= q <= 95 for q in (quality, catalog_quality, thumbnail_quality)):
        raise ValueError('WebP quality must be 75..95')
    # Recreate pixels to discard EXIF, GPS, comments, stale orientation tags and alpha.
    clean = flatten_alpha(image)
    result = {'width': clean.width, 'height': clean.height, 'photo_hash': photo_hash(clean)}
    for name, size, q in (('master', None, quality), ('catalog', catalog_side, catalog_quality), ('thumbnail', thumbnail_side, thumbnail_quality)):
        variant = clean if size is None else clean.copy()
        if size:
            variant.thumbnail((size, size), Image.Resampling.LANCZOS)
        stream = BytesIO()
        variant.save(stream, 'WEBP', quality=q, method=4)
        result[name] = base64.b64encode(stream.getvalue()).decode('ascii')
    return result


def verified_candidate(image: Image.Image, box=None) -> dict:
    import cv2
    selected = box.crop(image) if box else image
    small = selected.copy(); small.thumbnail((512,512))
    gray = np.asarray(small.convert('L'))
    blur = float(cv2.Laplacian(gray, cv2.CV_64F).var())
    # Grayscale spread detects near-blank captures (lens cap, white wall);
    # a heuristic flag only, never a probability or aesthetic score.
    gray_std = float(gray.std())
    tiny = np.asarray(selected.convert('L').resize((9,8)))
    bits = (tiny[:,1:] > tiny[:,:-1]).flatten()
    dhash = f'{sum(int(bit) << i for i, bit in enumerate(bits)):016x}'
    stream = BytesIO()
    clean = flatten_alpha(image)
    clean.save(stream, 'WEBP', quality=88)
    return {'master': base64.b64encode(stream.getvalue()).decode('ascii'),
            'photo_hash': photo_hash(image), 'dhash': dhash, 'blur': blur, 'gray_std': gray_std,
            'crop_pixels': selected.width*selected.height, 'min_side': min(selected.size)}
