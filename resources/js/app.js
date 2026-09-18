import './object-selection';

// One photo per request bounds POST size and PHP inference time for multi-upload.
document.querySelectorAll('[data-upload-designs]').forEach(form => {
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (form.dataset.uploading === 'true') return;
        const input = form.querySelector('input[name="images[]"]');
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

		const dataTransfer = new DataTransfer();

		/*
		 * Untuk admin:
		 * pertahankan foto yang sebelumnya sudah dipilih.
		 */
		if (mode === 'append') {
			Array.from(target.files ?? []).forEach((file) => {
				dataTransfer.items.add(file);
			});
		}

		/*
		 * Tambahkan foto dari kamera / galeri.
		 */
		Array.from(source.files).forEach((file) => {
			const duplicate = Array.from(dataTransfer.files).some(
				(existing) =>
					existing.name === file.name &&
					existing.size === file.size &&
					existing.lastModified === file.lastModified
			);

			if (!duplicate) {
				dataTransfer.items.add(file);
			}
		});

		/*
		 * Masukkan semua file ke input asli yang
		 * nantinya dikirim ke Laravel.
		 */
		target.files = dataTransfer.files;

		/*
		 * Trigger "change" agar kode preview
		 * di bawah ikut berjalan.
		 */
		target.dispatchEvent(
			new Event('change', {
				bubbles: true,
			})
		);

		/*
		 * Reset input kamera/galeri agar bisa
		 * digunakan lagi.
		 */
		source.value = '';

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
