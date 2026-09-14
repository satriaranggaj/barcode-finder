from fastapi import FastAPI, UploadFile, File, HTTPException
from PIL import Image
import io

from .model import create_image_embedding


app = FastAPI(
    title="Barcode Identify AI Service",
    version="1.0.0"
)


@app.get("/")
def root():

    return {
        "success": True,
        "service": "Barcode Identify AI",
        "model": "CLIP"
    }


@app.get("/health")
def health():

    return {
        "status": "ok"
    }


@app.post("/embed")
async def embed_image(
    image: UploadFile = File(...)
):

    try:

        contents = await image.read()

        pil_image = Image.open(
            io.BytesIO(contents)
        ).convert("RGB")

        embedding = create_image_embedding(
            pil_image
        )

        return {
            "success": True,
            "dimensions": len(embedding),
            "embedding": embedding
        }

    except Exception as e:

        raise HTTPException(
            status_code=500,
            detail=str(e)
        )