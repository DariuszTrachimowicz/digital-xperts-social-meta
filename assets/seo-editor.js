const DXSM_LABELS = { ok: 'Dobrze', brak: 'Problem', uwaga: 'Uwaga' };
const DXSM_RATINGS = { good: 'Dobrze', ok: 'Do poprawy', bad: 'Problemy', none: 'Bez oceny' };
// Długości, przy których Google zwykle pokazuje tytuł i opis w całości.
const DXSM_LENGTHS = { title: { short: 30, max: 60 }, description: { short: 70, max: 155 } };

// Analiza jednej treści. Wspólna dla edycji wpisu, panelu i przeliczania ocen w kokpicie.
function dxsmAnalyze(input) {
	const K = window.dxsmKeyphrase;
	// Spacja między sąsiednimi znacznikami: pytanie i odpowiedź w FAQ albo dwa elementy listy nie sklejają się w jedno słowo.
	const page = new DOMParser().parseFromString(String(input.html || '').replace(/<br\s*\/?>/gi, ' ').replace(/></g, '> <').replace(/<\/(p|h[1-6]|li|div|td|blockquote|figcaption)>/gi, '$&\n\n'), 'text/html');
	page.querySelectorAll('script, style').forEach((node) => node.remove());
	const text = (node) => node.textContent.trim();
	const body = page.body.textContent;
	const paragraph = [...page.querySelectorAll('p')].find(text);
	const seo = K.analyze({
		phrase: input.phrase,
		title: input.title,
		description: input.description,
		slug: input.slug,
		text: body,
		// Edytor klasyczny zapisuje akapity bez znaczników, oddzielone pustym wierszem.
		firstParagraph: paragraph ? text(paragraph) : body.split(/\n\s*\n/).find((part) => part.trim()) || '',
		headings: [...page.querySelectorAll('h2, h3, h4, h5, h6')].map(text),
	});

	// Śródtytuły dostają znacznik, żeby po zamianie treści na tekst dało się odróżnić je od akapitów.
	const marker = String.fromCharCode(1);
	const copy = page.body.cloneNode(true);
	copy.querySelectorAll('h2, h3, h4, h5, h6').forEach((heading) => {
		heading.textContent = '\n\n' + marker + heading.textContent;
	});
	// Skróty wtyczek w nawiasach kwadratowych nie są tekstem do czytania.
	const chunks = copy.textContent.replace(/\[\/?[a-z][a-z0-9_-]*[^\]]*\]/gi, ' ').split(/\n\s*\n/)
		.map((part) => part.trim()).filter(Boolean)
		.map((part) => ({ heading: part.startsWith(marker), text: part.replace(marker, '') }));
	const readability = K.readability(chunks);
	const style = K.style(body);
	// Za krótki tekst daje po jednej uwadze „za mało tekstu”: pokazujemy ją raz i nie wystawiamy oceny.
	const tooShort = readability.length === 1 && style.length === 1;
	const reading = tooShort ? readability : [...readability, ...style];

	// Ten sam wpis może być podlinkowany adresem pełnym albo względnym, z www albo bez, z ukośnikiem na końcu albo bez.
	// Z parametrów liczą się tylko te, które wskazują wpis (?p=123); parametry śledzące nie zmieniają celu linku.
	const own = input.url || window.location.href;
	const address = (href) => {
		try {
			const url = new URL(href, own);
			const id = ['p', 'page_id'].map((key) => (url.searchParams.get(key) ? key + '=' + url.searchParams.get(key) : '')).join('');
			return url.host.replace(/^www\./, '') + url.pathname.replace(/\/+$/, '') + '?' + id;
		} catch {
			return '';
		}
	};
	const candidates = input.candidates || [];
	const linked = new Set([...page.querySelectorAll('a[href]')].map((link) => address(link.getAttribute('href'))));
	const links = K.linkSuggestions(body, candidates.filter((candidate) => !linked.has(address(candidate.url))));

	return { seo, reading, links, scores: { seo: K.rate(seo), readability: tooShort ? 'none' : K.rate(reading) } };
}
window.dxsmAnalyze = dxsmAnalyze;

const dxsmPlural = (count, one, few, many) => {
	const tens = count % 100;
	const units = count % 10;
	return count + ' ' + (count === 1 ? one : units >= 2 && units <= 4 && (tens < 12 || tens > 14) ? few : many);
};

function dxsmCheckItem(check) {
	const item = document.createElement('li');
	const label = document.createElement('strong');
	item.dataset.status = check.status;
	label.textContent = DXSM_LABELS[check.status];
	item.append(label, ' ' + check.text);
	return item;
}

// Sekcja analizy: problemy i uwagi na wierzchu, dobre wyniki zwinięte pod spodem.
function dxsmRenderSection(editor, name, checks, emptyText) {
	const section = editor.querySelector(`[data-seo-section="${name}"]`);
	if (!section) {
		return;
	}
	const order = { brak: 0, uwaga: 1 };
	const issues = checks.filter((check) => check.status !== 'ok').sort((first, second) => order[first.status] - order[second.status]);
	const good = checks.filter((check) => check.status === 'ok');
	const problems = issues.filter((check) => check.status === 'brak').length;
	const notes = issues.length - problems;
	const list = section.querySelector('[data-seo-issues]');
	const empty = section.querySelector('[data-seo-empty]');
	list.replaceChildren(...issues.map(dxsmCheckItem));
	list.hidden = !issues.length;
	empty.textContent = checks.length ? (issues.length ? '' : 'Bez problemów i uwag.') : emptyText;
	empty.hidden = !!issues.length;
	const goodBox = section.querySelector('[data-seo-good]');
	goodBox.hidden = !good.length;
	goodBox.querySelector('summary').textContent = 'Dobre wyniki (' + good.length + ')';
	goodBox.querySelector('ul').replaceChildren(...good.map(dxsmCheckItem));
	section.querySelector('[data-seo-count]').textContent = checks.length
		? [problems ? dxsmPlural(problems, 'problem', 'problemy', 'problemów') : '', notes ? dxsmPlural(notes, 'uwaga', 'uwagi', 'uwag') : ''].filter(Boolean).join(', ') || 'bez uwag'
		: 'brak analizy';
	// Przy pierwszym pokazaniu otwarte są tylko sekcje, w których jest coś do zrobienia. Potem decyduje użytkownik.
	if (!section.dataset.ready) {
		section.dataset.ready = '1';
		section.open = issues.length > 0;
	}
}

function dxsmRenderLinks(editor, links, total) {
	const section = editor.querySelector('[data-seo-section="links"]');
	if (!section) {
		return;
	}
	const list = section.querySelector('[data-seo-links]');
	const empty = section.querySelector('[data-seo-empty]');
	empty.hidden = !!links.length;
	empty.textContent = total
		? 'Brak podpowiedzi: frazy kluczowe innych treści nie padają w tym tekście albo te treści są już podlinkowane.'
		: 'Podpowiedzi pojawią się, gdy inne treści dostaną frazę kluczową.';
	list.hidden = !links.length;
	list.replaceChildren(...links.map((suggestion) => {
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
	section.querySelector('[data-seo-count]').textContent = links.length ? String(links.length) : 'brak';
	if (!section.dataset.ready) {
		section.dataset.ready = '1';
		section.open = false;
	}
}

// Licznik długości pod polem: pasek i jedno zdanie, co ta długość oznacza w wynikach Google.
function dxsmRenderMeter(editor, name, value) {
	const meter = editor.querySelector(`[data-seo-meter="${name}"]`);
	if (!meter) {
		return;
	}
	const { short, max } = DXSM_LENGTHS[name];
	const length = [...value].length;
	const status = length > max ? 'uwaga' : length < short ? 'krotki' : 'ok';
	meter.dataset.status = status;
	meter.querySelector('[data-seo-meter-bar]').style.width = Math.min(100, Math.round((100 * length) / max)) + '%';
	meter.querySelector('[data-seo-meter-text]').textContent = dxsmPlural(length, 'znak', 'znaki', 'znaków')
		+ (status === 'uwaga' ? ', Google może uciąć końcówkę (do ' + max + ')' : status === 'krotki' ? ', krótko (najlepiej ' + short + ' do ' + max + ')' : ', w normie (do ' + max + ')');
}

// Edytor klasyczny: treść z pola edycji wpisu (wizualnego albo tekstowego), żeby ocena dotyczyła tego, co zaraz zostanie zapisane.
// W panelu Social Meta i w edytorze blokowym tego pola nie ma, wtedy funkcja zwraca null.
function dxsmClassicContent(editor) {
	const area = document.getElementById('content');
	if (!area || !editor.closest('#post')) {
		return null;
	}
	const visual = window.tinymce?.get('content');
	return visual && !visual.isHidden() ? visual.getContent() : area.value;
}

function dxsmRender(editor) {
	const saved = editor.querySelector('[data-seo-content]');
	const field = (name) => {
		const input = editor.querySelector(`[data-seo-input="${name}"]`);
		return input.value.trim() || input.dataset.automatic;
	};
	editor.querySelectorAll('[data-seo-input]').forEach((input) => dxsmRenderMeter(editor, input.dataset.seoInput, field(input.dataset.seoInput)));
	if (!saved) {
		return;
	}

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
	const source = editor.querySelector('[data-seo-link-candidates]');
	const candidates = source ? JSON.parse(source.textContent || '[]') : [];
	const result = dxsmAnalyze({
		html: live ? live.getEditedPostContent() : dxsmClassicContent(editor) ?? saved.value,
		phrase: editor.querySelector('[data-seo-keyphrase]').value,
		title,
		description: field('description'),
		// Strona główna nie ma adresu z nazwą wpisu, więc pole przychodzi bez data-slug i kontrola adresu znika.
		slug: saved.dataset.slug === undefined ? null : live?.getEditedPostAttribute('slug') || saved.dataset.slug,
		// Odsyłacze względne i kotwice (#koszt) liczą się od adresu edytowanej treści, nie od adresu panelu.
		url: saved.dataset.url,
		candidates,
	});

	dxsmRenderSection(editor, 'seo', result.seo, 'Wpisz frazę kluczową, żeby zobaczyć analizę SEO.');
	dxsmRenderSection(editor, 'reading', result.reading, '');
	dxsmRenderLinks(editor, result.links, candidates.length);
	for (const [name, label] of [['seo', 'SEO'], ['readability', 'Czytelność']]) {
		const rating = result.scores[name];
		const pill = editor.querySelector(`[data-seo-pill="${name}"]`);
		if (pill) {
			pill.dataset.rating = rating;
			pill.textContent = label + ': ' + (name === 'seo' && rating === 'none' ? 'brak frazy' : DXSM_RATINGS[rating]);
		}
		const store = editor.querySelector(`[data-seo-score="${name}"]`);
		if (store) {
			store.value = rating;
		}
	}
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
	dxsmRender(editor);
});

document.addEventListener('change', (event) => {
	const control = event.target;
	const editor = control.closest('[data-dxsm-seo]');
	if (editor && control.hasAttribute('data-seo-mode')) {
		editor.querySelector('[data-seo-google]').dataset.mode = control.value;
	}
});

const dxsmRenderAll = () => document.querySelectorAll('[data-dxsm-seo]').forEach(dxsmRender);
dxsmRenderAll();
// Przed wysłaniem formularza wpisu oceny liczą się jeszcze raz, już z aktualnej treści edytora klasycznego.
document.addEventListener('submit', (event) => {
	if (event.target.id === 'post') {
		dxsmRenderAll();
	}
}, true);
let dxsmRenderTimer;
window.wp?.data?.subscribe?.(() => {
	clearTimeout(dxsmRenderTimer);
	dxsmRenderTimer = setTimeout(dxsmRenderAll, 500);
});
