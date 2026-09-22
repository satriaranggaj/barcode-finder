import './object-selection';
import { compressImages, compressionNeeded } from './image-compression';
import { handleSearchSubmit, resetSearchButtons } from './submit-loading';
import { resetSearchProgress } from './search-progress';
import { emitCompression, initSearchReadiness } from './search-readiness';

// One photo per request bounds POST size and PHP inference time for multi-upload.
document.querySelectorAll('[data-upload-designs]').forEach(form => {
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (form.dataset.uploading === 'true') return;
        const input = form.querySelector('input[name="images[]"]');
        // Never submit a half-compressed state: wait for any in-flight
        // background compression before reading input.files.
        try { await (input?._lenskuCompress || Promise.resolve()); } catch { /* fallback file already set */ }
        const files = [...(input?.files || [])];
        if (!files.length || files.length > 10) { alert('Pilih 1 sampai 10 foto.'); return; }
        const snapshot = new FormData(form);
        form.dataset.uploading = 'true';
        const status = document.createElement('p'); status.setAttribute('role','status'); form.append(status);
        let completed = 0;
        try {
            let redirect;
            for (const [i,file] of files.entries()) {
                status.textContent = `Menyimpan foto ${i+1} dari ${files.length}…`;
                const body = new FormData();
                body.append('_token', snapshot.get('_token'));
                body.append('images[0]',file);
                body.append('crop_coordinates[0]',snapshot.get(`crop_coordinates[${i}]`) || '');
                body.append('selection_sources[0]',snapshot.get(`selection_sources[${i}]`) || 'full');
                const response = await fetch(form.action,{method:'POST',body,headers:{Accept:'application/json'}});
                const data = await response.json().catch(()=>({}));
                if (!response.ok) throw new Error(data.message || 'Upload gagal. Periksa foto yang sudah tersimpan sebelum mencoba lagi.');
                completed++; redirect=data.redirect;
            }
            window.location.assign(redirect || window.location.href);
        } catch (error) {
            status.textContent = `${completed} foto berhasil disimpan. ${error.message}`;
            // Remove only confirmed successes so retries cannot duplicate them.
            const pending = new DataTransfer(); files.slice(completed).forEach(file=>pending.items.add(file));
            input.files=pending.files; input.dispatchEvent(new Event('change',{bubbles:true}));
        } finally { form.dataset.uploading = 'false'; }
    });
});

/*
|--------------------------------------------------------------------------
| PHOTO PICKER - KAMERA / GALERI
|--------------------------------------------------------------------------
*/

// Buka / tutup pilihan Kamera & Galeri
document.querySelectorAll('[data-photo-picker]').forEach((button) => {
	button.addEventListener('click', () => {
		const menu = document.getElementById(button.dataset.photoMenu);

		if (!menu) return;

		menu.hidden = !menu.hidden;
	});
});

// Ambil file dari Kamera / Galeri
document.querySelectorAll('[data-photo-source]').forEach((source) => {
	source.addEventListener('change', () => {
		if (!source.files?.length) return;

		const target = document.getElementById(source.dataset.photoTarget);
		const mode = source.dataset.photoMode;

		if (!target) return;

		const incoming = Array.from(source.files);
		// Reset segera agar pemilih yang sama bisa dipakai lagi walau
		// kompresi masih berjalan.
		source.value = '';

		/*
		 * Untuk admin:
		 * pertahankan foto yang sebelumnya sudah dipilih.
		 */
		const kept = mode === 'append' ? Array.from(target.files ?? []) : [];

		// Dedup mentah sebelum kompresi (nama/ukuran masih original).
		const merged = [...kept];
		incoming.forEach((file) => {
			const duplicate = merged.some(
				(existing) =>
					existing.name === file.name &&
					existing.size === file.size &&
					existing.lastModified === file.lastModified
			);

			if (!duplicate) {
				merged.push(file);
			}
		});

		/*
		 * Tampilkan instan: isi input dengan file original dulu agar
		 * preview + object selection langsung berjalan tanpa menunggu
		 * kompresi full-res yang berat. Resolusi tidak berubah saat
		 * kompresi, jadi koordinat crop tetap konsisten.
		 */
		const immediate = new DataTransfer();
		merged.forEach((file) => immediate.items.add(file));
		target.files = immediate.files;
		target._lenskuSkipOnce = true;
		target.dispatchEvent(
			new Event('change', {
				bubbles: true,
			})
		);

		// Kompresi latar: resolusi dipertahankan, hanya file size yang
		// dikurangi. Hasil ditukar diam-diam tanpa change ulang (tanpa
		// selection ganda); submit menunggu promise ini. File yang sudah
		// dioptimalkan dilewati via marker internal (tanpa double lossy).
		// Guard revisi: pilihan baru yang masuk saat kompresi berjalan
		// ditangani task yang lebih baru, hasil basi dibuang.
		const revision = (target._lenskuRevision = (target._lenskuRevision || 0) + 1);
		target._lenskuCompress = (async () => {
			try {
				const optimized = await compressImages(merged);
				// Guarded terminal point: stale results return silently and
				// never emit, so Foto A cannot rewrite Foto B's readiness.
				if (target._lenskuRevision !== revision) return [...(target.files || [])];
				emitCompression(target, 'ready', revision);
				// Dedup pasca-kompresi: memilih foto galeri yang sama dua
				// kali menghasilkan nama+ukuran output yang identik.
				const unique = [];
				optimized.forEach((file) => {
					if (!unique.some((existing) => existing.name === file.name && existing.size === file.size)) {
						unique.push(file);
					}
				});
				const changed = unique.length !== merged.length || unique.some((file, index) => file !== merged[index]);
				if (changed) {
					const dataTransfer = new DataTransfer();
					unique.forEach((file) => dataTransfer.items.add(file));
					target.files = dataTransfer.files;
				}
			} catch {
				// Fallback: file original sudah terpasang, upload tetap jalan.
				// Readiness treats fallback as satisfied, never as fatal.
				emitCompression(target, 'fallback', revision);
			}
			return [...(target.files || [])];
		})();

		/*
		 * Tutup menu Kamera / Galeri.
		 */
		document.querySelectorAll('[data-photo-menu]').forEach((button) => {
			const menu = document.getElementById(button.dataset.photoMenu);
			if (menu) menu.hidden = true;
		});
	});
});

// Pengaman untuk input yang diisi langsung tanpa lewat picker
// (programmatic/set via devtools): kompresi latar lalu tukar diam-diam.
// Preview/selection langsung memakai file awal; submit menunggu promise.
document.addEventListener('change', (event) => {
	const input = event.target?.matches?.('input[data-image-preview]') ? event.target : null;
	if (!input) return;
	if (input._lenskuSkipOnce) {
		input._lenskuSkipOnce = false;
		return;
	}
	const files = [...(input.files || [])];
	if (!compressionNeeded(files)) return;
	const revision = (input._lenskuRevision = (input._lenskuRevision || 0) + 1);
	input._lenskuCompress = (async () => {
		try {
			const optimized = await compressImages(files);
			if (input._lenskuRevision !== revision) return [...(input.files || [])];
			emitCompression(input, 'ready', revision);
			if (optimized.length !== files.length || optimized.some((file, index) => file !== files[index])) {
				const dataTransfer = new DataTransfer();
				optimized.forEach((file) => dataTransfer.items.add(file));
				input.files = dataTransfer.files;
			}
		} catch {
			// Fallback: file awal tetap terpasang.
			emitCompression(input, 'fallback', revision);
		}
		return [...(input.files || [])];
	})();
});

// Search form memakai submit native: tahan submit sampai kompresi latar selesai.
document.querySelectorAll('form').forEach((form) => {
	if (form.hasAttribute('data-upload-designs')) return;
	const imageInput = form.querySelector('input[data-image-preview][name="image"]');
	if (!imageInput) return;
	// Loading tombol hanya aktif di sini: event submit native fire strictly
	// setelah validasi browser lolos. Gating kompresi + fail-closed finalize
	// ditangani handleSearchSubmit (teruji); tombol tetap aktif sampai
	// navigasi terjadi (pencarian AI butuh waktu di server).
	form.addEventListener('submit', (event) => {
		handleSearchSubmit(form, imageInput, event);
	});
});

// Reset tombol dan progress bar bila user kembali dengan tombol back
// (bfcache bisa menampilkan state loading yang basi). Tanpa ini pencarian
// berikutnya bisa terkunci dalam keadaan disabled/hidden yang basi.
window.addEventListener('pageshow', () => {
	resetSearchButtons(document);
	resetSearchProgress(document);
});


/*
|--------------------------------------------------------------------------
| IMAGE PREVIEW
|--------------------------------------------------------------------------
*/

document.querySelectorAll('[data-image-preview]').forEach((input) => {
	input.addEventListener('change', () => {
		const file = input.files?.[0];
		const preview = document.getElementById(input.dataset.previewTarget);
		const image = preview?.querySelector('[data-preview-image]');
		const fileName = preview?.querySelector('[data-preview-name]');

		const submit = input.dataset.submitTarget
			? document.getElementById(input.dataset.submitTarget)
			: input.form?.querySelector('[data-preview-submit]');

		if (!file || !file.type.startsWith('image/')) {
			if (preview) preview.hidden = true;
			if (submit) submit.hidden = true;
			return;
		}

		if (image) {
			if (image.dataset.objectUrl) URL.revokeObjectURL(image.dataset.objectUrl);
			image.src = URL.createObjectURL(file);
			image.dataset.objectUrl = image.src;
		}

		if (fileName) {
			/*
			 * Kalau memilih beberapa foto,
			 * tampilkan jumlahnya.
			 */
			if (input.files.length > 1) {
				fileName.textContent =
					`${input.files.length} foto dipilih`;
			} else {
				fileName.textContent = file.name;
			}
		}

		if (preview) preview.hidden = false;
		if (submit) submit.hidden = false;
	});
});


/*
|--------------------------------------------------------------------------
| MOBILE NAVIGATION
|--------------------------------------------------------------------------
*/

document.querySelectorAll('[data-design-slider]').forEach((slider) => {
	const track = slider.querySelector('[data-design-track]');
	const slides = Array.from(track.querySelectorAll('[data-design-slide]'));
	const position = slider.querySelector('[data-design-position]');
	const dots = Array.from(slider.querySelectorAll('[data-design-dot]'));
	let current = 0;

	const move = (step) => {
		current = (current + step + slides.length) % slides.length;
		track.scrollTo({
			left: current * track.clientWidth,
			behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth',
		});
	};

	slider.querySelector('[data-design-previous]').addEventListener('click', () => move(-1));
	slider.querySelector('[data-design-next]').addEventListener('click', () => move(1));
	dots.forEach((dot, index) => {
		dot.addEventListener('click', () => move(index - current));
	});
	track.addEventListener('scroll', () => {
		current = Math.round(track.scrollLeft / track.clientWidth);
		position.textContent = `Desain ${current + 1} / ${slides.length}`;
		dots.forEach((dot, index) => dot.setAttribute('aria-current', String(index === current)));
	}, { passive: true });
	track.tabIndex = 0;
	track.addEventListener('keydown', (event) => {
		if (event.target !== track || !['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
		event.preventDefault();
		move(event.key === 'ArrowLeft' ? -1 : 1);
	});
});

const navToggle = document.querySelector('[data-nav-toggle]');
const mobileNavigation = document.getElementById('mobile-navigation');

navToggle?.addEventListener('click', () => {
	const isOpen = navToggle.getAttribute('aria-expanded') === 'true';

	navToggle.setAttribute('aria-expanded', String(!isOpen));
	navToggle.textContent = isOpen ? '☰' : '×';

	if (mobileNavigation) {
		mobileNavigation.hidden = isOpen;
	}
});

// Explicit readiness UI for visual-search preparation. Observes only:
// never slows preview, selection, or compression, which all start first.
initSearchReadiness();
