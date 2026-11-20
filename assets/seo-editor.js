const LABELS = { ok: 'Dobrze', brak: 'Brak', uwaga: 'Uwaga' };

function renderLinks(editor, page, body) {
	const list = editor.querySelector('[data-seo-links]');
	const source = editor.querySelector('[data-seo-link-candidates]');
	if (!list || !source) {
		return;
	}

	const all = JSON.parse(source.textContent || '[]');
	// Odsyłacze względne i kotwice (#koszt) liczą się od adresu edytowanej treści, nie od adresu panelu.
	const own = editor.querySelector('[data-seo-content]').dataset.url || window.location.href;
	// Ten sam wpis może być podlinkowany adresem pełnym albo względnym, z www albo bez, z ukośnikiem na końcu albo bez.
	// Z parametrów liczą się tylko te, które wskazują wpis (?p=123); parametry śledzące nie zmieniają celu linku.
	const address = (href) => {
		try {
			const url = new URL(href, own);
			const id = ['p', 'page_id'].map((key) => (url.searchParams.get(key) ? key + '=' + url.searchParams.get(key) : '')).join('');
			return url.host.replace(/^www\./, '') + url.pathname.replace(/\/+$/, '') + '?' + id;
		} catch {
			return '';
		}
	};
	const linked = new Set([...page.querySelectorAll('a[href]')].map((link) => address(link.getAttribute('href'))));
	const found = window.dxsmKeyphrase.linkSuggestions(body, all.filter((candidate) => !linked.has(address(candidate.url))));
	if (!found.length) {
		const empty = document.createElement('li');
		empty.textContent = all.length
			? 'Brak podpowiedzi: frazy kluczowe innych treści nie padają w tym tekście albo te treści są już podlinkowane.'
			: 'Podpowiedzi pojawią się, gdy inne treści dostaną frazę kluczową.';
		list.replaceChildren(empty);
		return;
	}
	list.replaceChildren(...found.map((suggestion) => {
		const item = document.createElement('li');
		const link = document.createElement('a');
		const where = document.createElement('span');
		link.href = suggestion.url;
		link.target = '_blank';
		link.rel = 'noopener';
		link.textContent = suggestion.title;
		where.textContent = 'Fraza „' + suggestion.phrase + '” pada w zdaniu: „' + suggestion.sentence.slice(0, 160) + (suggestion.sentence.length > 160 ? '…' : '') + '”';
		item.append(link, where);
		if (navigator.clipboard) {
			const copy = document.createElement('button');
			copy.type = 'button';
			copy.className = 'button button-small';
			copy.dataset.seoCopy = suggestion.url;
			copy.textContent = 'Kopiuj adres';
			item.append(copy);
		}
		return item;
	}));
}

function renderChecks(list, checks) {
	list?.replaceChildren(...checks.map((check) => {
		const item = document.createElement('li');
		const label = document.createElement('strong');
		item.dataset.status = check.status;
		label.textContent = LABELS[check.status];
		item.append(label, ' ' + check.text);
		return item;
	}));
}

function renderReadability(editor, page) {
	const list = editor.querySelector('[data-seo-readability]');
	if (!list) {
		return;
	}

	// Śródtytuły dostają znacznik, żeby po zamianie treści na tekst dało się odróżnić je od akapitów.
	const marker = String.fromCharCode(1);
	const copy = page.body.cloneNode(true);
	copy.querySelectorAll('h2, h3, h4, h5, h6').forEach((heading) => {
		heading.textContent = '\n\n' + marker + heading.textContent;
	});
	// Skróty wtyczek w nawiasach kwadratowych nie są tekstem do czytania.
	const chunks = copy.textContent.replace(/\[\/?[a-z][a-z0-9_-]*[^\]]*\]/gi, ' ').split(/\n\s*\n/)
		.map((text) => text.trim()).filter(Boolean)
		.map((text) => ({ heading: text.startsWith(marker), text: text.replace(marker, '') }));
	renderChecks(list, window.dxsmKeyphrase.readability(chunks));
}

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
	// Spacja między sąsiednimi znacznikami: pytanie i odpowiedź w FAQ albo dwa elementy listy nie sklejają się w jedno słowo.
	const page = new DOMParser().parseFromString(html.replace(/<br\s*\/?>/gi, ' ').replace(/></g, '> <').replace(/<\/(p|h[1-6]|li|div|td|blockquote|figcaption)>/gi, '$&\n\n'), 'text/html');
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
	renderChecks(list, checks);
	renderLinks(editor, page, body);
	renderReadability(editor, page);
	renderChecks(editor.querySelector('[data-seo-style]'), window.dxsmKeyphrase.style(body));
}

document.addEventListener('click', (event) => {
	const copy = event.target.closest?.('[data-seo-copy]');
	if (copy) {
		navigator.clipboard.writeText(copy.dataset.seoCopy).then(() => {
			copy.textContent = 'Skopiowano';
		});
	}
});

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
