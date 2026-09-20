import './object-selection';
import { compressImages, isCompressible, savingText } from './image-compression';

// One photo per request bounds POST size and PHP inference time for multi-upload.
document.querySelectorAll('[data-upload-designs]').forEach(form => {
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (form.dataset.uploading === 'true') return;
        const input = form.querySelector('input[name="images[]"]');
        // Never submit a half-compressed state: wait for any in-flight
        // client compression before reading input.files.
        try { await (input?._lenskuCompress || Promise.resolve()); } catch { /* fallback file already set */ }
        if (input?.dataset?.compressing === 'true') return;
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
	source.addEventListener('change', async () => {
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

		// Kompresi otomatis: resolusi dipertahankan, hanya file size
		// yang dikurangi. File yang sudah dioptimalkan dilewati lewat
		// marker internal sehingga tidak terjadi double lossy.
		const task = (async () => {
			setCompressing(target, true, 'Mengoptimalkan foto…');
			try {
				const originalBytes = merged.reduce((sum, file) => sum + (file.size || 0), 0);
				const optimized = await compressImages(merged);
				// Dedup pasca-kompresi: memilih foto galeri yang sama dua
				// kali menghasilkan nama+ukuran output yang identik.
				const unique = [];
				optimized.forEach((file) => {
					if (!unique.some((existing) => existing.name === file.name && existing.size === file.size)) {
						unique.push(file);
					}
				});
				const dataTransfer = new DataTransfer();
				unique.forEach((file) => dataTransfer.items.add(file));
				target.files = dataTransfer.files;
				const saving = savingText(originalBytes, unique.reduce((sum, file) => sum + (file.size || 0), 0));
				setCompressing(target, false, saving ? `Foto siap diunggah · ${saving}` : 'Foto siap diunggah');
			} catch {
				// Fallback: pakai file original agar upload tidak gagal.
				const dataTransfer = new DataTransfer();
				merged.forEach((file) => dataTransfer.items.add(file));
				target.files = dataTransfer.files;
				setCompressing(target, false, '');
			}
			return [...(target.files || [])];
		})();
		target._lenskuCompress = task;
		await task;

		/*
		 * Trigger "change" agar kode preview
		 * di bawah ikut berjalan (selalu SETELAH kompresi selesai,
		 * sehingga preview + object selection memakai file final).
		 */
		target.dispatchEvent(
			new Event('change', {
				bubbles: true,
			})
		);

		/*
		 * Tutup menu Kamera / Galeri.
		 */
		document.querySelectorAll('[data-photo-menu]').forEach((button) => {
			const menu = document.getElementById(button.dataset.photoMenu);
			if (menu) menu.hidden = true;
		});
	});
});

/*
 |--------------------------------------------------------------------------
 | COMPRESSION STATUS + SUBMIT GATING
 |--------------------------------------------------------------------------
 |
 | Selama kompresi berjalan: tampilkan status ringan dan kunci tombol
 | submit agar tidak ada file setengah diproses yang terkirim.
 | Search form (submit native) menunggu promise kompresi sebelum submit.
 */

function compressStatusElement(input) {
	const preview = input.dataset.previewTarget ? document.getElementById(input.dataset.previewTarget) : null;
	const host = preview || input.form || input.parentElement;
	if (!host) return null;
	let status = host.querySelector?.('[data-compress-status]');
	if (!status) {
		status = document.createElement('p');
		status.setAttribute('data-compress-status', '');
		status.setAttribute('role', 'status');
		status.className = 'mt-2 text-xs font-semibold text-[#8b5e00]';
		(preview || host).prepend(status);
	}
	return status;
}

function setCompressing(input, active, text) {
	if (!input) return;
	if (active) input.dataset.compressing = 'true';
	else delete input.dataset.compressing;
	const form = input.form;
	form?.querySelectorAll?.('[data-preview-submit], button[type="submit"]').forEach((button) => {
		if (active) {
			if (button.dataset.lenskuDisabled !== 'true') {
				button.dataset.lenskuDisabled = 'true';
				button.setAttribute('aria-disabled', 'true');
				button.classList.add('opacity-50', 'pointer-events-none');
			}
		} else if (button.dataset.lenskuDisabled === 'true') {
			delete button.dataset.lenskuDisabled;
			button.removeAttribute('aria-disabled');
			button.classList.remove('opacity-50', 'pointer-events-none');
		}
	});
	const status = compressStatusElement(input);
	if (status) {
		status.textContent = text || '';
		status.hidden = !text;
	}
}

// Pengaman untuk input yang diisi langsung tanpa lewat picker
// (programmatic/set via devtools): kompres sebelum preview/selection.
document.addEventListener('change', async (event) => {
	const input = event.target?.matches?.('input[data-image-preview]') ? event.target : null;
	if (!input || input._lenskuApplying || input.dataset.compressing === 'true') return;
	const files = [...(input.files || [])];
	if (!files.length) return;
	if (!files.some((file) => isCompressible(file))) return;
	event.stopImmediatePropagation();
	input._lenskuApplying = true;
	setCompressing(input, true, 'Mengoptimalkan foto…');
	try {
		const originalBytes = files.reduce((sum, file) => sum + (file.size || 0), 0);
		const optimized = await compressImages(files);
		const dataTransfer = new DataTransfer();
		optimized.forEach((file) => dataTransfer.items.add(file));
		input.files = dataTransfer.files;
		input._lenskuCompress = Promise.resolve([...input.files]);
		const saving = savingText(originalBytes, optimized.reduce((sum, file) => sum + (file.size || 0), 0));
		setCompressing(input, false, saving ? `Foto siap diunggah · ${saving}` : 'Foto siap diunggah');
	} catch {
		setCompressing(input, false, '');
	} finally {
		input._lenskuApplying = false;
	}
	input.dispatchEvent(new Event('change', { bubbles: true }));
}, true);

// Search form memakai submit native: tahan submit sampai kompresi selesai.
document.querySelectorAll('form').forEach((form) => {
	if (form.hasAttribute('data-upload-designs')) return;
	const imageInput = form.querySelector('input[data-image-preview][name="image"]');
	if (!imageInput) return;
	form.addEventListener('submit', async (event) => {
		if (form.dataset.lenskuResubmit === 'true') {
			delete form.dataset.lenskuResubmit;
			return;
		}
		try { await (imageInput._lenskuCompress || Promise.resolve()); } catch { /* fallback file sudah terpasang */ }
		if (imageInput.dataset.compressing === 'true') {
			event.preventDefault();
			try { await (imageInput._lenskuCompress || Promise.resolve()); } catch { /* noop */ }
			form.dataset.lenskuResubmit = 'true';
			form.requestSubmit();
		}
	});
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
