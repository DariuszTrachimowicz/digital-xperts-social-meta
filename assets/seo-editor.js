document.addEventListener('input', (event) => {
	const field = event.target;
	const editor = field.closest('[data-dxsm-seo]');
	if (!editor || !field.hasAttribute('data-seo-input')) {
		return;
	}

	const value = field.value.trim().replace(/\s+/g, ' ') || field.dataset.automatic;
	editor.querySelectorAll('[data-seo-preview]').forEach((preview) => {
		if (preview.dataset.seoPreview === field.dataset.seoInput) {
			preview.textContent = value;
		}
	});
});

document.addEventListener('change', (event) => {
	const control = event.target;
	const editor = control.closest('[data-dxsm-seo]');
	if (editor && control.hasAttribute('data-seo-mode')) {
		editor.querySelector('[data-seo-google]').dataset.mode = control.value;
	}
});
