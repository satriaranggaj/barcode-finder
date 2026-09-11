import asyncio
import logging
from time import perf_counter

from fastapi import FastAPI, UploadFile, File, Form, HTTPException
from starlette.concurrency import run_in_threadpool

from .model import create_image_embedding, MODEL_NAME, MODEL_REVISION
from .preprocessing import VERSION, MAX_BYTES, decode, prepare, cv2
from .descriptors import describe
from .conditional_preprocessing import prepare as prepare_conditional, VERSION as CONDITIONAL_VERSION

app = FastAPI(title="Barcode Identify AI Service", version="2.0.0")
gate = asyncio.Semaphore(1)
logger = logging.getLogger("lensku.ai")
if cv2 is not None:
    cv2.setNumThreads(1)


@app.get("/")
def root():
    return {"success": True, "service": "Barcode Identify AI", "model": "CLIP"}


@app.get("/health")
def health():
    return {"status": "ok", "model": MODEL_NAME, "model_revision": MODEL_REVISION, "preprocessing_version": VERSION,
            "segmenter_available": cv2 is not None, "supported_preprocessing": [VERSION, CONDITIONAL_VERSION]}


def extract(contents, legacy=False, pipeline=VERSION, details=True, descriptors_only=False, signals=None):
    if pipeline not in (VERSION, CONDITIONAL_VERSION):
        raise ValueError('unsupported_preprocessing_version')
    started = perf_counter()
    image, timings = decode(contents, legacy=legacy)
    try:
        prepared = None if legacy else (prepare_conditional(image) if pipeline == CONDITIONAL_VERSION else prepare(image))
        embedding_started = perf_counter()
        embedding = None if descriptors_only else create_image_embedding(image if legacy else prepared.image)
        timings["embedding_ms"] = round((perf_counter()-embedding_started)*1000, 3)
        response = {"success": True}
        if embedding is not None:
            response.update(dimensions=len(embedding), embedding=embedding)
        if not legacy:
            response.update(version=pipeline, embedding_model=MODEL_NAME, model_revision=MODEL_REVISION,
                            preprocessing=prepared.metadata)
            descriptor_started = perf_counter()
            try:
                if details:
                    response['descriptors'] = describe(prepared, signals)
            except Exception:
                response['descriptors'] = {'version': 'visual-v1', 'status': 'unavailable'}
                logger.warning('descriptor_fallback')
            timings['descriptor_ms'] = round((perf_counter()-descriptor_started)*1000, 3)
            timings.update(prepared.metadata['timings'])
        timings["total_ms"] = round((perf_counter()-started)*1000, 3)
        response["timings"] = timings
        logger.info("image_features version=%s mode=%s total_ms=%s", "legacy" if legacy else pipeline,
                    "original" if legacy else prepared.metadata["mode"], timings["total_ms"])
        return response
    finally:
        image.close()


async def process(image, legacy, pipeline=VERSION, details=True, descriptors_only=False, signals=None):
    acquired = False
    try:
        await asyncio.wait_for(gate.acquire(), timeout=2)
        acquired = True
        contents = await image.read(MAX_BYTES+1)
        if len(contents) > MAX_BYTES:
            raise HTTPException(413, "image_size_limit")
        return await run_in_threadpool(extract, contents, legacy, pipeline, details, descriptors_only, signals)
    except asyncio.TimeoutError:
        raise HTTPException(503, "inference_busy")
    except ValueError:
        raise HTTPException(422, "invalid_or_unsafe_image")
    except HTTPException:
        raise
    except Exception:
        logger.exception("image_inference_failed")
        raise HTTPException(503, "inference_unavailable")
    finally:
        if acquired:
            gate.release()
        await image.close()


@app.post("/embed")
async def embed_image(image: UploadFile = File(...)):
    # Kept as the original preprocessing contract for existing references.
    return await process(image, True)


@app.post("/features")
async def image_features(image: UploadFile = File(...), pipeline: str = Form(VERSION), details: bool = Form(True)):
    return await process(image, False, pipeline, details)


@app.post('/descriptors')
async def query_descriptors(image: UploadFile = File(...), pipeline: str = Form(CONDITIONAL_VERSION), signals: str = Form('color,texture')):
    selected = signals.split(',') if signals else []
    if not set(selected).issubset({'color','texture','shape','proportion','local'}):
        raise HTTPException(422, 'unknown_descriptor')
    # Stateless: no unbounded image cache, no reference reads, and no second embedding.
    return await process(image, False, pipeline, True, True, selected)
