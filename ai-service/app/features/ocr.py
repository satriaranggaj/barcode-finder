from typing import Protocol
from PIL import Image


class TextExtractor(Protocol):
    def extract_text(self, image: Image.Image) -> list[str]: ...


class DisabledOCR:
    """Inject a future local OCR provider without coupling it to encoders or ranking."""
    def extract_text(self, image: Image.Image) -> list[str]:
        return []
