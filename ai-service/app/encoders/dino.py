from .huggingface import HuggingFaceEncoder


class DinoEncoder(HuggingFaceEncoder):
    """DINOv2 normalized CLS representation for visual appearance retrieval."""
    def __init__(self, model_id: str, revision: str, device: str, batch_size: int = 2):
        super().__init__(model_id, revision, device, batch_size, 'cls')
