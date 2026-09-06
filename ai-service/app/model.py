from PIL import Image
import torch
from transformers import CLIPProcessor, CLIPModel


MODEL_NAME = "openai/clip-vit-base-patch32"

device = "cuda" if torch.cuda.is_available() else "cpu"

print(f"Loading CLIP model...")
print(f"Device: {device}")

processor = CLIPProcessor.from_pretrained(MODEL_NAME)

model = CLIPModel.from_pretrained(MODEL_NAME)

model.to(device)
model.eval()

print("CLIP model loaded.")


def create_image_embedding(image: Image.Image):

    inputs = processor(
        images=image,
        return_tensors="pt"
    )

    inputs = {
        key: value.to(device)
        for key, value in inputs.items()
    }

    with torch.no_grad():

        image_features = model.get_image_features(
            **inputs
        )

    if hasattr(image_features, "pooler_output"):
        image_features = image_features.pooler_output

    # Normalize vector
    image_features = image_features / image_features.norm(
        dim=-1,
        keepdim=True
    )

    embedding = image_features[0].cpu().tolist()

    return embedding