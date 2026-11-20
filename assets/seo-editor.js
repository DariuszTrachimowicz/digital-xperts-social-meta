const LABELS = { ok: 'Dobrze', brak: 'Brak', uwaga: 'Uwaga' };

function renderKeyphrase(editor) {
	const list = editor.querySelector('[data-seo-keyphrase-checks]');
	if (!list) {
		return;
	}

	const field = (name) => {
		const input = editor.querySelector(`[data-seo-input="${name}"]`);
		return input.value.trim() || input.dataset.automatic;
	};
	const saved = editor.querySelector('[data-seo-content]');
	let live;
	try {
		// Edytor blokowy: treść, tytuł i adres sprzed zapisu. Gdzie indziej analiza czyta dane zapisane.
		live = window.wp?.data?.select('core/editor');
	} catch {
		live = null;
	}
	if (!live?.getCurrentPostId?.()) {
		live = null;
	}
	const html = live ? live.getEditedPostContent() : saved.value;
	const page = new DOMParser().parseFromString(html.replace(/<br\s*\/?>/gi, ' ').replace(/<\/(p|h[1-6]|li|div|td|blockquote|figcaption)>/gi, '$&\n\n'), 'text/html');
	page.querySelectorAll('script, style').forEach((node) => node.remove());
	const text = (node) => node.textContent.trim();
	const body = page.body.textContent;
	const paragraph = [...page.querySelectorAll('p')].find(text);
	let title = field('title');
	const postTitle = live?.getEditedPostAttribute('title');
	const savedTitle = saved.dataset.postTitle;
	if (postTitle && postTitle !== savedTitle && !editor.querySelector('[data-seo-input="title"]').value.trim()) {
		// Tytuł automatyczny i data-post-title pochodzą z chwili otwarcia edycji: podmieniamy w nim tytuł wpisu na aktualny.
		// Wtyczka zamienia w tytułach półpauzy i pauzy na dywiz, więc porównujemy po tej samej zamianie.
		const tidy = (value) => value.replace(new RegExp('[' + String.fromCharCode(8211, 8212) + ']', 'g'), '-').replace(/\s+/g, ' ').trim();
		const old = tidy(savedTitle || '');
		// Gdy tytuł automatyczny nie zawiera tytułu wpisu (stały tytuł z modułu pełnego SEO), zostaje bez zmian.
		title = old ? tidy(title).replace(old, () => postTitle) : postTitle + ' ' + title;
	}
	const checks = window.dxsmKeyphrase.analyze({
		phrase: editor.querySelector('[data-seo-keyphrase]').value,
		title,
		description: field('description'),
		// Strona główna nie ma adresu z nazwą wpisu, więc pole przychodzi bez data-slug i kontrola adresu znika.
		slug: saved.dataset.slug === undefined ? null : live?.getEditedPostAttribute('slug') || saved.dataset.slug,
		text: body,
		// Edytor klasyczny zapisuje akapity bez znaczników, oddzielone pustym wierszem.
		firstParagraph: paragraph ? text(paragraph) : body.split(/\n\s*\n/).find((part) => part.trim()) || '',
		headings: [...page.querySelectorAll('h2, h3, h4, h5, h6')].map(text),
	});
	list.replaceChildren(...checks.map((check) => {
		const item = document.createElement('li');
		const label = document.createElement('strong');
		item.dataset.status = check.status;
		label.textContent = LABELS[check.status];
		item.append(label, ' ' + check.text);
		return item;
	}));
}

document.addEventListener('input', (event) => {
	const field = event.target;
	const editor = field.closest('[data-dxsm-seo]');
	if (!editor) {
		return;
	}

	if (field.hasAttribute('data-seo-input')) {
		const value = field.value.trim().replace(/\s+/g, ' ') || field.dataset.automatic;
		editor.querySelectorAll('[data-seo-preview]').forEach((preview) => {
			if (preview.dataset.seoPreview === field.dataset.seoInput) {
				preview.textContent = value;
			}
		});
	}
	renderKeyphrase(editor);
});

document.addEventListener('change', (event) => {
	const control = event.target;
	const editor = control.closest('[data-dxsm-seo]');
	if (editor && control.hasAttribute('data-seo-mode')) {
		editor.querySelector('[data-seo-google]').dataset.mode = control.value;
	}
});

const renderAllKeyphrases = () => document.querySelectorAll('[data-dxsm-seo]').forEach(renderKeyphrase);
renderAllKeyphrases();
let keyphraseTimer;
window.wp?.data?.subscribe?.(() => {
	clearTimeout(keyphraseTimer);
	keyphraseTimer = setTimeout(renderAllKeyphrases, 500);
});
