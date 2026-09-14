"""FastAPI transport; inference runs in a bounded worker section."""
from contextlib import asynccontextmanager
import logging
from threading import Lock
from typing import Literal
from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from .config import Settings
from .preprocessing.pipeline import decode_image, InvalidImage
from .search.service import RetrievalService, load_encoders
from .search.faiss_index import FaissIndexManager, current_generation

logger = logging.getLogger(__name__)


def create_app(settings: Settings | None = None, service: RetrievalService | None = None,
               legacy_encoder=None) -> FastAPI:
    settings = settings or Settings.from_env()
    lock = Lock()

    @asynccontextmanager
    async def lifespan(app: FastAPI):
        index = None
        app.state.retrieval = service
        app.state.legacy = legacy_encoder
        app.state.error = None
        if service is None:
            try:
                encoders = load_encoders(settings)
                try:
                    index = FaissIndexManager.load_index(current_generation(settings.index_path))
                except FileNotFoundError:
                    logger.warning('Index missing; build and restart to enable /search')
                app.state.retrieval = RetrievalService(settings, encoders, index)
                logger.info('Retrieval ready; references=%s', index.count if index else 0)
            except Exception:
                logger.exception('Retrieval initialization failed')
                app.state.error = 'Retrieval initialization failed; inspect service logs'
            if settings.legacy_embed:
                try:
                    from .encoders.huggingface import HuggingFaceEncoder
                    import torch
                    device = ('cuda' if torch.cuda.is_available() else 'cpu') if settings.device == 'auto' else settings.device
                    app.state.legacy = HuggingFaceEncoder('openai/clip-vit-base-patch32',
                                                         'main', device, 1, 'image_features')
                except Exception:
                    logger.exception('Legacy CLIP unavailable')
        yield
        if index is not None:
            index.close()

    app = FastAPI(title='Lensku Fine-grained SKU Retrieval', version='2.0.0', lifespan=lifespan)

    @app.get('/health')
    def health():
        active = app.state.retrieval
        return {'status': 'ok' if active and active.index is not None and len(active.encoders) == 2 else 'degraded',
                'models': list(active.encoders) if active else [],
                'references': active.index.count if active and active.index else 0,
                'search_ready': bool(active and active.index is not None),
                'legacy_embed_ready': app.state.legacy is not None, 'error': app.state.error}

    @app.get('/')
    def root():
        return {'success': True, 'service': 'Lensku SKU retrieval', 'docs': '/docs'}

    def read_image(upload: UploadFile):
        try:
            data = upload.file.read(settings.max_bytes + 1)
            if len(data) > settings.max_bytes:
                raise HTTPException(413, 'Upload exceeds MAX_BYTES')
            return decode_image(data, settings.max_bytes, settings.max_pixels)
        except InvalidImage as exc:
            raise HTTPException(422, str(exc)) from exc
        finally:
            upload.file.close()

    def require_service() -> RetrievalService:
        if app.state.retrieval is None:
            raise HTTPException(503, 'Retrieval models unavailable; inspect /health')
        return app.state.retrieval

    @app.post('/search')
    def search(image: UploadFile = File(...), top_k: int = Form(settings.default_top_k, ge=1, le=50),
               category: str | None = Form(None, max_length=200),
               preprocessing_mode: Literal['original', 'object'] = Form(settings.preprocessing_mode)):
        if not lock.acquire(blocking=False):
            raise HTTPException(503, 'Inference busy; retry later', headers={'Retry-After': '2'})
        try:
            source = read_image(image)
            return require_service().search(source, top_k, category, preprocessing_mode)
        except RuntimeError as exc:
            raise HTTPException(503, str(exc)) from exc
        finally:
            lock.release()

    @app.post('/embed')
    def embed(image: UploadFile = File(...), representation: Literal['legacy', 'multi'] = Form('legacy'),
              preprocessing_mode: Literal['original', 'object'] = Form(settings.preprocessing_mode)):
        if not lock.acquire(blocking=False):
            raise HTTPException(503, 'Inference busy; retry later', headers={'Retry-After': '2'})
        try:
            source = read_image(image)
            if representation == 'legacy':
                if app.state.legacy is None:
                    raise HTTPException(503, 'Legacy embedding disabled or unavailable')
                vector = app.state.legacy.encode_image(source)
                return {'success': True, 'dimensions': len(vector), 'embedding': vector.tolist()}
            vectors, info = require_service().embed(source, preprocessing_mode)
            return {'success': True, 'query': info,
                    'global': {name: crops['global'].tolist() for name, crops in vectors.items()},
                    'local': [{'name': crop, **{name: values[crop].tolist() for name, values in vectors.items()}}
                              for crop in ('center', 'left', 'right', 'top', 'bottom')]}
        except RuntimeError as exc:
            raise HTTPException(503, str(exc)) from exc
        finally:
            lock.release()

    return app


app = create_app()
