from .huggingface import HuggingFaceEncoder


class SiglipEncoder(HuggingFaceEncoder):
    """SigLIP2 pooled image representation; never concatenate with DINO."""
    def __init__(self, model_id: str, revision: str, device: str, batch_size: int = 2):
        super().__init__(model_id, revision, device, batch_size, 'image_features')
