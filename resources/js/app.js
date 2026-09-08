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
		document.querySelectorAll('[data-photo-menu]').forEach((menu) => {
			menu.hidden = true;
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
			image.src = URL.createObjectURL(file);
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