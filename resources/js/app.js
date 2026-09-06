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

		if (image) image.src = URL.createObjectURL(file);
		if (fileName) fileName.textContent = file.name;
		if (preview) preview.hidden = false;
		if (submit) submit.hidden = false;
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
