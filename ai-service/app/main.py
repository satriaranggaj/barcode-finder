import asyncio
from time import perf_counter
from fastapi import FastAPI, UploadFile, File, HTTPException
from starlette.concurrency import run_in_threadpool
from .model import create_image_embedding, MODEL_REVISION
from .visual import decode, focus, descriptors, VERSION, MAX_BYTES, MODEL_SHA

app = FastAPI(title='Lensku Visual Search')
slots = asyncio.Semaphore(1)


def extract(contents):
    started = perf_counter()
    try:
        image = decode(contents)
    except (ValueError, OSError) as error:
        raise ValueError('invalid_image') from error
    focused, mask, metadata = focus(image)
    return {'pipeline': VERSION, 'model_revision': MODEL_REVISION, 'object_model_sha256': MODEL_SHA,
            'embedding': create_image_embedding(focused), 'features': descriptors(focused, mask),
            'metadata': metadata, 'latency_ms': (perf_counter()-started)*1000}


@app.get('/health')
@app.get('/')
def health():
    return {'status': 'ok', 'pipeline': VERSION}


@app.post('/features')
async def features(image: UploadFile = File(...)):
    try:
        await asyncio.wait_for(slots.acquire(), timeout=2)
    except TimeoutError:
        raise HTTPException(503, 'AI busy; retry later')
    try:
        contents = await image.read(MAX_BYTES+1)
        return await run_in_threadpool(extract, contents)
    except ValueError as error:
        raise HTTPException(422, 'Image cannot be processed safely') from error
    except Exception as error:
        raise HTTPException(503, 'Visual search temporarily unavailable') from error
    finally:
        slots.release()
        await image.close()
