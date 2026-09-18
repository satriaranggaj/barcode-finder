"""Shared inference mechanics, independent of FastAPI."""
import numpy as np
from PIL import Image
from .base import ImageEncoder, normalize


class HuggingFaceEncoder(ImageEncoder):
    def __init__(self, model_id: str, revision: str, device: str, batch_size: int,
                 kind: str) -> None:
        import torch
        import transformers
        from transformers import AutoImageProcessor, AutoModel
        self.torch = torch
        self.device = device
        self.batch_size = batch_size
        self.kind = kind
        self.model_id, self.revision = model_id, revision
        self.tokenizer = None
        self.processor = AutoImageProcessor.from_pretrained(model_id, revision=revision, use_fast=False)
        self.model = AutoModel.from_pretrained(model_id, revision=revision).to(device).eval()
        # Record resolved revision, not a moving branch name, to reject incompatible indexes.
        resolved = getattr(self.model.config, '_commit_hash', None)
        if resolved is None and revision == 'main':
            raise ValueError('Model revision could not be resolved; pin a commit revision')
        self.identity = {'model': model_id, 'revision': resolved or revision, 'pooling': kind,
                         'processor': 'slow-v1', 'transformers': transformers.__version__}

    def encode_images(self, images: list[Image.Image]) -> np.ndarray:
        batches = []
        for offset in range(0, len(images), self.batch_size):
            inputs = self.processor(images=images[offset:offset + self.batch_size], return_tensors='pt')
            inputs = {key: value.to(self.device) for key, value in inputs.items()}
            with self.torch.inference_mode():
                if self.kind == 'cls':
                    features = self.model(**inputs).last_hidden_state[:, 0]
                else:
                    features = self.model.get_image_features(**inputs)
                    if hasattr(features, 'pooler_output'):
                        features = features.pooler_output
            batches.append(features.float().cpu().numpy())
        return normalize(np.concatenate(batches, axis=0))

    def encode_text(self, text: str) -> np.ndarray:
        if self.kind != 'image_features':
            raise ValueError('Encoder does not support paired image/text features')
        if self.tokenizer is None:
            from transformers import AutoTokenizer
            self.tokenizer = AutoTokenizer.from_pretrained(self.model_id, revision=self.identity['revision'])
        length = self.model.config.text_config.max_position_embeddings
        inputs = self.tokenizer([text], padding='max_length', truncation=True, max_length=length, return_tensors='pt')
        inputs = {key:value.to(self.device) for key,value in inputs.items()}
        with self.torch.inference_mode():
            vector = self.model.get_text_features(**inputs)
            if hasattr(vector,'pooler_output'): vector=vector.pooler_output
        return normalize(vector[0].float().cpu().numpy())
