"""FastAPI transport; inference runs in a bounded worker section."""
from contextlib import asynccontextmanager
import logging
import time
from threading import Lock, RLock
from typing import Literal
from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from .config import Settings
from .preprocessing.pipeline import decode_image, InvalidImage
from .search.service import RetrievalService, load_encoders
from .search.faiss_index import FaissIndexManager, current_generation
from .preprocessing.selection import BoundingBox, parse_selection, propose_for_ui, default_pipeline
from .preprocessing.images import prepare_upload, verified_candidate
from .schemas import SearchResponse

logger = logging.getLogger(__name__)


class SnapshotPool:
    """One current serving snapshot plus retired ones with use-counting.

    A swap never closes a busy snapshot: retired entries close exactly when
    their last in-flight request releases them (immediately when idle).
    All mutations hold one lock, which is never held during inference, so
    reload checks cannot cause busy-503s.
    """

    def __init__(self) -> None:
        # Re-entrant: the reload check holds this lock while swap() takes it
        # again in the same thread. Never held during inference itself.
        self.lock = RLock()
        self.current: RetrievalService | None = None
        self.generation: str | None = None
        self.retired: list = []

    def acquire(self) -> RetrievalService | None:
        with self.lock:
            service = self.current
            if service is None:
                return None
            service._inflight = getattr(service, '_inflight', 0) + 1
            return service

    def release(self, service: RetrievalService | None) -> None:
        if service is None:
            return
        with self.lock:
            service._inflight = max(0, getattr(service, '_inflight', 1) - 1)
            if getattr(service, '_retired', False) and service._inflight == 0:
                _close_snapshot(service)
                try:
                    self.retired.remove(service)
                except ValueError:
                    pass

    def swap(self, service: RetrievalService, generation: str) -> None:
        with self.lock:
            old = self.current
            self.current = service
            self.generation = generation
            if old is None or not getattr(old, '_owned', False):
                return
            if getattr(old, '_inflight', 0) == 0:
                _close_snapshot(old)
            else:
                old._retired = True
                self.retired.append(old)


def _close_snapshot(service) -> None:
    try:
        if getattr(service, '_owned', False) and getattr(service, 'index', None) is not None:
            service.index.close()
    except Exception:
        logger.exception('Error closing retired index')


def create_app(settings: Settings | None = None, service: RetrievalService | None = None,
               legacy_encoder=None) -> FastAPI:
    settings = settings or Settings.from_env()
    # Separate locks: heavy inference (/search, /embed) must never block the
    # light interactive path (/select, /prepare). Previously one global lock
    # returned 503 for auto-selection while a search was running, so the box
    # appeared stuck right after upload. Light endpoints share one lock;
    # heavy endpoints share another.
    search_lock = Lock()
    select_lock = Lock()

    @asynccontextmanager
    async def lifespan(app: FastAPI):
        index = None
        pool = SnapshotPool()
        app.state.pool = pool
        pool.current = service
        pool.generation = None
        app.state.retrieval = service
        # Reloads only apply to app-owned snapshots. Tests pass an explicit
        # service (pre-set managed=False keeps them hermetic); production
        # boots with service=None and manages its own lifecycle.
        if not hasattr(app.state, 'managed'):
            app.state.managed = service is None
        app.state.generation = None
        app.state.legacy = legacy_encoder
        # Complementary proposal sources: GrabCut is precise on clean shots,
        # saliency/colour-pop/contours cover the clutter where it abstains. The
        # accepted area window and padding stay configurable; every proposal is
        # user-editable and ranked best-first by a shared heuristic. The same
        # factory backs serving and indexing so boxes never diverge.
        app.state.selection = default_pipeline(
            settings.selection_padding,
            settings.selection_min_area, settings.selection_max_area)
        app.state.error = None
        if service is None:
            try:
                encoders = load_encoders(settings)
                try:
                    index = FaissIndexManager.load_index(current_generation(settings.index_path))
                except FileNotFoundError:
                    logger.warning('Index missing; build and restart to enable /search')
                app.state.retrieval = RetrievalService(settings, encoders, index)
                app.state.retrieval._owned = True
                app.state.pool.current = app.state.retrieval
                try:
                    app.state.pool.generation = current_generation(settings.index_path).name
                except Exception:
                    app.state.pool.generation = None
                app.state.generation = app.state.pool.generation
                logger.info('Retrieval ready; references=%s', index.count if index else 0)
                policy = getattr(app.state.retrieval, 'policy', None)
                if policy is not None:
                    logger.info('Relevance gating active; threshold=%s', policy.get('threshold'))
                else:
                    logger.info('Relevance gating disabled; /search returns unfiltered Top-K')
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
        pool = app.state.pool
        with pool.lock:
            current = pool.current
            retired, pool.retired = pool.retired, []
            pool.current = None
            app.state.retrieval = None
        # Only lifespan/reload-owned snapshots are closed here; a service
        # passed in by the caller (tests, embedding) stays owned by them.
        for service_snapshot in ([current] if current is not None else []) + retired:
            _close_snapshot(service_snapshot)

    app = FastAPI(title='Lensku Fine-grained SKU Retrieval', version='2.0.0', lifespan=lifespan)

    @app.get('/health')
    def health():
        pool = app.state.pool
        with pool.lock:
            active = pool.current
            generation = pool.generation
        # Fallback for callers holding an older reference; pool is source of truth.
        if active is None:
            active = app.state.retrieval
            generation = app.state.generation
        policy = getattr(active, 'policy', None)
        return {'status': 'ok' if active and active.index is not None and len(active.encoders) == 2 else 'degraded',
                'models': list(active.encoders) if active else [],
                'references': active.index.count if active and active.index else 0,
                'search_ready': bool(active and active.index is not None),
                'generation': generation,
                'relevance_gated': policy is not None,
                'relevance_threshold': policy.get('threshold') if isinstance(policy, dict) else None,
                'legacy_embed_ready': app.state.legacy is not None, 'error': app.state.error}

    @app.get('/')
    def root():
        return {'success': True, 'service': 'Lensku SKU retrieval', 'docs': '/docs'}

    def read_image(upload: UploadFile):
        try:
            data = upload.file.read(settings.max_bytes + 1)
            if len(data) > settings.max_bytes:
                raise HTTPException(413, 'Upload exceeds MAX_BYTES')
            return decode_image(data, settings.max_bytes, settings.max_pixels, settings.processing_memory_mb)
        except InvalidImage as exc:
            raise HTTPException(422, str(exc)) from exc
        finally:
            upload.file.close()

    def require_service() -> RetrievalService:
        if app.state.retrieval is None:
            raise HTTPException(503, 'Retrieval models unavailable; inspect /health')
        return app.state.retrieval

    def _published_generation() -> str | None:
        try:
            return current_generation(settings.index_path).name
        except Exception:
            return None

    def _pin_rejected(name: str) -> None:
        """Pin a corrupt generation so it is not retried on every request."""
        pool = app.state.pool
        with pool.lock:
            if name != pool.generation:
                logger.exception('Hot reload rejected generation %s; keeping previous snapshot', name)
                pool.generation = name
                app.state.generation = name

    def _swap_loaded(name: str, new) -> None:
        pool = app.state.pool
        with pool.lock:
            if name != pool.generation:
                pool.swap(new, name)
                app.state.retrieval = new
                app.state.generation = name
                logger.info('Hot reload published generation %s; references=%s', name, new.index.count)
            else:
                # Lost the race: another request already swapped; close ours.
                _close_snapshot(new)

    def current_service() -> RetrievalService:
        """Serving snapshot with generation-aware hot reload.

        The CURRENT pointer is a tiny file read per request; the heavy load
        runs only when the published name actually changed. The load happens
        OUTSIDE the pool lock so concurrent searches never block on FAISS /
        SQLite I/O — only the pointer swap takes the lock. In-flight requests
        keep their own snapshot reference, so a swap can never close an index
        underneath a running search (retired snapshots close at zero users).
        """
        pool = app.state.pool
        service = pool.acquire()
        if service is None:
            raise HTTPException(503, 'Retrieval models unavailable; inspect /health')
        name = _published_generation()
        if not getattr(app.state, 'managed', False) or name is None or name == pool.generation:
            return service
        # Release first so the swap path sees a clean count; the heavy load
        # below runs without holding the lock.
        old_encoders = service.encoders if service is not None else None
        pool.release(service)
        index = None
        try:
            index = FaissIndexManager.load_index(settings.index_path / name)
            encoders = old_encoders if old_encoders is not None else load_encoders(settings)
            new = RetrievalService(settings, encoders, index)
            new._owned = True
        except Exception:
            if index is not None:
                try:
                    index.close()
                except Exception:
                    pass
            # Invalid generation (partial/corrupt): keep serving the old one.
            # The name is pinned so the same broken generation is not retried
            # on every request; a fixed rebuild publishes under a new name.
            _pin_rejected(name)
            service = pool.acquire()
            if service is None:
                raise HTTPException(503, 'Retrieval models unavailable; inspect /health')
            return service
        _swap_loaded(name, new)
        service = pool.acquire()
        if service is None:
            raise HTTPException(503, 'Retrieval models unavailable; inspect /health')
        return service

    def release_service(service: RetrievalService) -> None:
        app.state.pool.release(service)

    @app.post('/search', response_model=SearchResponse)
    def search(image: UploadFile = File(...), top_k: int = Form(settings.default_top_k, ge=1, le=50),
               category: str | None = Form(None, max_length=200),
               preprocessing_mode: Literal['original', 'object'] = Form(settings.preprocessing_mode),
               selection_mode: Literal['auto', 'manual', 'full'] | None = Form(None),
               crop_x: float | None = Form(None), crop_y: float | None = Form(None),
               crop_width: float | None = Form(None), crop_height: float | None = Form(None),
               feedback_preview: bool = Form(False)):
        if not search_lock.acquire(blocking=False):
            raise HTTPException(503, 'Inference busy; retry later', headers={'Retry-After': '2'})
        try:
            started = time.perf_counter()
            source = read_image(image)
            decode_ms = (time.perf_counter() - started) * 1000
            box = parse_selection((crop_x, crop_y, crop_width, crop_height), selection_mode)
            if selection_mode == 'full':
                preprocessing_mode = 'original'
            service = current_service()
            try:
                result = service.search(source, top_k, category, preprocessing_mode, box=box,
                                        selection_mode=selection_mode)
            finally:
                release_service(service)
            result.setdefault('stage_ms', {})['decode_ms'] = decode_ms
            result.setdefault('query', {})['selection_mode'] = selection_mode or ('manual' if box else 'auto')
            if feedback_preview:
                selected = result['query'].get('selection_used')
                try:
                    result['feedback'] = verified_candidate(source, BoundingBox(**selected) if selected else None)
                except (ValueError, OSError, RuntimeError):
                    logger.exception('Optional feedback preview unavailable')
            result['latency_ms'] = (time.perf_counter() - started) * 1000
            return result
        except ValueError as exc:
            raise HTTPException(422, str(exc)) from exc
        except RuntimeError as exc:
            raise HTTPException(503, str(exc)) from exc
        finally:
            search_lock.release()

    @app.post('/select')
    def select(image: UploadFile = File(...)):
        if not select_lock.acquire(blocking=False):
            raise HTTPException(503, 'Processing busy')
        try:
            # UI convenience: an editable center box beats an empty canvas,
            # while the search pipeline keeps its full-image fallback.
            return propose_for_ui(read_image(image), app.state.selection.selector)
        finally:
            select_lock.release()

    @app.post('/prepare')
    def prepare(image: UploadFile = File(...), quality: int = Form(None, ge=75, le=95),
                catalog_side: int = Form(None, ge=1200, le=1600), thumbnail_side: int = Form(None, ge=300, le=500),
                catalog_quality: int = Form(None, ge=75, le=95), thumbnail_quality: int = Form(None, ge=75, le=95)):
        if not select_lock.acquire(blocking=False):
            raise HTTPException(503, 'Processing busy')
        try:
            return prepare_upload(image.file.read(settings.max_bytes+1), settings.max_bytes,
                                  settings.max_pixels, settings.processing_memory_mb,
                                  quality or settings.master_quality,
                                  catalog_side or settings.catalog_side,
                                  thumbnail_side or settings.thumbnail_side,
                                  catalog_quality or settings.catalog_quality,
                                  thumbnail_quality or settings.thumbnail_quality)
        except InvalidImage as exc:
            raise HTTPException(422, str(exc)) from exc
        finally:
            image.file.close()
            select_lock.release()

    @app.post('/embed')
    def embed(image: UploadFile = File(...), representation: Literal['legacy', 'multi'] = Form('legacy'),
              preprocessing_mode: Literal['original', 'object'] = Form(settings.preprocessing_mode),
              selection_mode: Literal['auto', 'manual', 'full'] | None = Form(None),
              crop_x: float | None = Form(None), crop_y: float | None = Form(None),
              crop_width: float | None = Form(None), crop_height: float | None = Form(None)):
        if not search_lock.acquire(blocking=False):
            raise HTTPException(503, 'Inference busy; retry later', headers={'Retry-After': '2'})
        try:
            source = read_image(image)
            box = parse_selection((crop_x, crop_y, crop_width, crop_height), selection_mode)
            if box is not None:
                source = box.crop(source)
            if representation == 'legacy':
                if app.state.legacy is None:
                    raise HTTPException(503, 'Legacy embedding disabled or unavailable')
                vector = app.state.legacy.encode_image(source)
                return {'success': True, 'dimensions': len(vector), 'embedding': vector.tolist()}
            # A manual crop defines the region; never re-propose on the cropped image.
            # Small auto boxes fall back to full inside embed(); manual boxes
            # are honoured whatever their size.
            vectors, info = require_service().embed(source, 'original' if box is not None or selection_mode == 'full' else preprocessing_mode,
                                                    box=box, selection_mode=selection_mode)
            info['selection_mode'] = selection_mode or ('manual' if box else 'auto')
            return {'success': True, 'query': info,
                    'global': {name: crops['global'].tolist() for name, crops in vectors.items()},
                    'local': [{'name': crop, **{name: values[crop].tolist() for name, values in vectors.items()}}
                              for crop in ('center', 'left', 'right', 'top', 'bottom')]}
        except ValueError as exc:
            raise HTTPException(422, str(exc)) from exc
        except RuntimeError as exc:
            raise HTTPException(503, str(exc)) from exc
        finally:
            search_lock.release()

    return app


app = create_app()
