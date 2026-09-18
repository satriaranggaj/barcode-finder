"""Modular CPU OCR for fine-grained markings (PH2, 10MM, 1.5", model codes).

Free/open-source only (Tesseract executable), CPU-compatible, optional and
bounded: one downscaled invocation per query at most, confidence-filtered
words only. Low-confidence output is dropped, never treated as truth.
A missing engine degrades gracefully to no words. Structured results carry
text, normalized tokens, engine confidence and normalized boxes; the legacy
extract_text() string list is preserved so ranking call sites are untouched.
"""
import math
from dataclasses import dataclass
from typing import Protocol
from PIL import Image

MAX_WORDS = 32
MAX_TEXT_LENGTH = 80
# TSV word level emitted by `tesseract ... tsv`.
WORD_LEVEL = '5'


def normalize_token(text: str) -> str:
    """Uppercase, whitespace-collapsed token; markings keep their raw form.

    Deliberately no OCR-confusion correction (0/O, 1/I): without font
    context such rewrites invent evidence, so confusable readings are left
    to fail matching instead of matching wrongly.
    """
    return ' '.join(str(text or '').upper().split())


def confidence_weight(mean_confidence: float, minimum: float) -> float:
    """Map mean word confidence to a secondary-evidence weight in 0..1.

    Words at the acceptance floor contribute ~nothing; certain words fully.
    A heuristic contribution scale, never a probability.
    """
    if not math.isfinite(mean_confidence) or not math.isfinite(minimum):
        return 0.0
    if mean_confidence < minimum:
        return 0.0
    if minimum >= 100:
        return 1.0
    return (mean_confidence - minimum) / (100 - minimum)


@dataclass(frozen=True)
class OcrWord:
    text: str
    tokens: tuple[str, ...]
    confidence: float
    # Normalized 0..1 box on the examined image; None when the engine omits it.
    box: tuple[float, float, float, float] | None = None


@dataclass(frozen=True)
class OcrResult:
    words: tuple[OcrWord, ...] = ()
    engine: str = 'none'

    @property
    def texts(self) -> list[str]:
        return [word.text for word in self.words]

    @property
    def tokens(self) -> list[str]:
        return [token for word in self.words for token in word.tokens]


class TextExtractor(Protocol):
    def extract_text(self, image: Image.Image) -> list[str]: ...

    def extract_words(self, image: Image.Image) -> OcrResult: ...


class DisabledOCR:
    """Inject a future local OCR provider without coupling it to encoders or ranking."""
    def extract_text(self, image: Image.Image) -> list[str]:
        return []

    def extract_words(self, image: Image.Image) -> OcrResult:
        return OcrResult()


@dataclass
class TesseractOCR:
    """Optional local executable; bounded work and confidence-filtered words only."""
    binary: str = 'tesseract'
    minimum_confidence: float = 80

    def extract_text(self, image: Image.Image) -> list[str]:
        return self.extract_words(image).texts

    def extract_words(self, image: Image.Image) -> OcrResult:
        import csv
        import io
        import subprocess
        import tempfile
        from pathlib import Path
        try:
            with tempfile.TemporaryDirectory(prefix='lensku-ocr-') as temporary:
                small = image.copy(); small.thumbnail((1024, 1024))
                width, height = small.size
                path = Path(temporary) / 'image.png'; small.save(path)
                result = subprocess.run([self.binary, str(path), 'stdout', '--psm', '11', 'tsv'],
                                        capture_output=True, text=True, timeout=3, check=True)
                return self.parse_tsv(result.stdout, width, height)
        except (OSError, ValueError, subprocess.SubprocessError):
            return OcrResult(engine=self.binary)

    def parse_tsv(self, tsv: str, width: int, height: int) -> OcrResult:
        import csv
        import io
        words: list[OcrWord] = []
        reader = csv.DictReader(io.StringIO(tsv), delimiter='\t')
        for row in reader:
            if (row.get('level') or '') != WORD_LEVEL:
                continue
            text = (row.get('text') or '').strip()
            if not text:
                continue
            try:
                confidence = float(row.get('conf', -1))
            except (TypeError, ValueError):
                continue
            # Low-confidence words are dropped, never passed downstream.
            if not math.isfinite(confidence) or not 0 <= confidence <= 100 or confidence < self.minimum_confidence:
                continue
            text = text[:MAX_TEXT_LENGTH]
            tokens = tuple(token for token in (normalize_token(text),) if token)
            box = self._normalized_box(row, width, height)
            words.append(OcrWord(text=text[:MAX_TEXT_LENGTH], tokens=tokens,
                                 confidence=confidence, box=box))
            if len(words) >= MAX_WORDS:
                break
        return OcrResult(words=tuple(words), engine=self.binary)

    @staticmethod
    def _normalized_box(row: dict, width: int, height: int) -> tuple[float, float, float, float] | None:
        try:
            left, top = float(row['left']), float(row['top'])
            w, h = float(row['width']), float(row['height'])
        except (KeyError, TypeError, ValueError):
            return None
        if not all(math.isfinite(v) for v in (left, top, w, h)) or width <= 0 or height <= 0 or w <= 0 or h <= 0:
            return None
        box = (left / width, top / height, (left + w) / width, (top + h) / height)
        if min(box) < 0 or max(box) > 1.0000001:
            return None
        return box
