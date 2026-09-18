"""Decode safely before any model invocation; segmentation failure is nonfatal."""
from dataclasses import dataclass
from hashlib import sha256
from io import BytesIO
import warnings
from PIL import Image, ImageOps, UnidentifiedImageError
from .foreground import ForegroundExtractor, GrabCutForeground

VERSION = 'product-crops-v1'


class InvalidImage(ValueError):
    pass


def flatten_alpha(image: Image.Image, background=(255, 255, 255)) -> Image.Image:
    """Composite any transparency over a solid background and return RGB.

    A plain convert('RGB') discards the alpha channel without blending, which
    surfaces hidden RGB values under transparent pixels. Compositing first keeps
    WebP masters and AI inputs free of black fringes on transparent PNGs.
    """
    if image.mode == 'RGB':
        return image
    canvas = Image.new('RGBA', image.size, (*background, 255))
    return Image.alpha_composite(canvas, image.convert('RGBA')).convert('RGB')


def decode_image(data: bytes, max_bytes: int, max_pixels: int, memory_mb: int = 256) -> Image.Image:
    if not data or len(data) > max_bytes:
        raise InvalidImage('Empty image or upload exceeds MAX_BYTES')
    try:
        with warnings.catch_warnings():
            warnings.simplefilter('error', Image.DecompressionBombWarning)
            with Image.open(BytesIO(data)) as probe:
                if probe.format not in ('JPEG', 'PNG', 'WEBP') or getattr(probe, 'n_frames', 1) != 1:
                    raise InvalidImage('Only single-frame JPEG, PNG and WebP are supported')
                if probe.width * probe.height > max_pixels:
                    raise InvalidImage('Image exceeds MAX_PIXELS')
                probe.verify()
            with Image.open(BytesIO(data)) as source:
                # Bound peak decode/re-encode memory independently of upload bytes.
                # JPEG can decode at a smaller native scale without allocating full pixels.
                budget = memory_mb * 1024 * 1024
                if source.width*source.height*12 + len(data)*2 + 16*1024*1024 > budget:
                    if source.format == 'JPEG':
                        source.draft('RGB', (max(1,source.width//2), max(1,source.height//2)))
                    if source.width*source.height*12 + len(data)*2 + 16*1024*1024 > budget:
                        raise InvalidImage('Image exceeds safe processing memory; reduce image dimensions')
                source.load()  # Detect truncated/corrupted pixels, not just a valid header.
                return flatten_alpha(ImageOps.exif_transpose(source))
    except (OSError, ValueError, UnidentifiedImageError, Image.DecompressionBombWarning,
            Image.DecompressionBombError) as exc:
        raise InvalidImage('Invalid, corrupt or oversized image') from exc


def photo_hash(image: Image.Image) -> str:
    """Canonical decoded-pixel identity catches identical photos in different containers."""
    return sha256(str(image.size).encode() + image.tobytes()).hexdigest()


@dataclass
class PreparedImage:
    image: Image.Image
    mode: str
    reason: str


class Preprocessor:
    def __init__(self, max_side: int = 1024, foreground: ForegroundExtractor | None = None):
        self.max_side = max_side
        self.foreground = foreground or GrabCutForeground()

    def prepare(self, source: Image.Image, mode: str = 'object') -> PreparedImage:
        if mode not in ('original', 'object'):
            raise ValueError('Invalid preprocessing mode')
        image = flatten_alpha(ImageOps.exif_transpose(source))
        image.thumbnail((self.max_side, self.max_side), Image.Resampling.LANCZOS)
        reason, actual = 'original_requested', 'original'
        if mode == 'object':
            try:
                box = self.foreground.box(image)
                if box is None:
                    reason = 'foreground_uncertain'
                elif not (0 <= box[0] < box[2] <= image.width and 0 <= box[1] < box[3] <= image.height):
                    reason = 'foreground_invalid_box'
                else:
                    image = image.crop(box)
                    actual, reason = 'object', 'foreground_crop'
            except Exception:
                reason = 'foreground_failed'
        return PreparedImage(image, actual, reason)


def pad_square(image: Image.Image) -> Image.Image:
    """Pad each crop without stretching; model processor owns normalization."""
    side = max(image.size)
    result = Image.new('RGB', (side, side), (127, 127, 127))
    result.paste(image, ((side-image.width)//2, (side-image.height)//2))
    return result
