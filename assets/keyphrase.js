// Fraza kluczowa z polską odmianą: lekki stemmer końcówek, bez słownika.
// shortcut: stopniowanie (tani, tańszy) i formy nieregularne (człowiek, ludzie) nie są łączone; słownik wyjątków, gdy zacznie to przeszkadzać.
(function (root) {
	const VOWELS = 'aeiouyąęó';
	// Od najdłuższych: końcówki rzeczowników, przymiotników i czasowników.
	const ENDINGS = [
		'ujecie', 'ujemy', 'ujesz', 'ować', 'ywać', 'iwać',
		'ach', 'ami', 'owi', 'iem', 'ego', 'emu', 'ymi', 'imi', 'ych', 'ich', 'uje', 'uję', 'ują', 'ają', 'asz', 'amy', 'isz', 'ysz', 'imy', 'ymy',
		'em', 'om', 'ów', 'ie', 'ym', 'im', 'ej', 'am', 'ać', 'ić', 'yć',
		'ą', 'ę', 'a', 'y', 'i', 'e', 'o', 'u',
	].sort((a, b) => b.length - a.length);
	const SOFT = { ź: 'z', ć: 'c', ś: 's', ń: 'n' };
	// Wymiany spółgłosek na końcu tematu: usługa i usłudze, klient i klienci, biuro i biurze, szkoła i szkole.
	const ALTERNATIONS = [['dz', 'g'], ['dz', 'd'], ['c', 'k'], ['c', 't'], ['rz', 'r'], ['l', 'ł'], ['śc', 'st'], ['sz', 'ch'], ['ż', 'g'], ['z', 'g']];
	const cache = new Map();

	function stem(word) {
		let stemmed = word.toLowerCase();
		for (let changed = stemmed.length > 3; changed;) {
			changed = false;
			for (const ending of ENDINGS) {
				if (stemmed.endsWith(ending) && stemmed.length - ending.length >= 3) {
					stemmed = stemmed.slice(0, -ending.length);
					changed = true;
					break;
				}
			}
		}
		return stemmed.replace(/[źćśń]$/, (soft) => SOFT[soft]).replace(/ó/g, 'o').replace(/ą/g, 'ę');
	}

	// Wszystkie postaci tematu, pod którymi słowo może się spotkać z inną swoją formą.
	function keys(word) {
		if (cache.has(word)) {
			return cache.get(word);
		}
		const base = stem(word);
		const result = new Set([base]);
		// Ruchome e: źródeł i źródło, wyszukiwarek i wyszukiwarka.
		const mobile = base.length > 4 && !VOWELS.includes(base.at(-1)) && base.at(-2) === 'e' && !VOWELS.includes(base.at(-3))
			? base.slice(0, -2) + base.at(-1)
			: '';
		for (const form of mobile ? [base, mobile] : [base]) {
			result.add(form);
			for (const [from, to] of ALTERNATIONS) {
				if (form.endsWith(from) && form.length - from.length >= 2) {
					result.add(form.slice(0, -from.length) + to);
				}
			}
		}
		cache.set(word, result);
		return result;
	}

	function sameWord(first, second) {
		const other = keys(second);
		for (const key of keys(first)) {
			if (other.has(key)) {
				return true;
			}
		}
		return false;
	}

	function words(text) {
		return String(text || '').toLowerCase().match(/[a-ząćęłńóśźż0-9]+/g) || [];
	}

	// Adresy stron nie mają polskich znaków, więc frazę porównujemy z adresem bez nich.
	function plain(text) {
		return String(text || '').toLowerCase().replace(/ł/g, 'l').normalize('NFD').replace(/[̀-ͯ]/g, '');
	}

	// Spójniki i przyimki nie liczą się do frazy: „sklep z butami” to „sklep” i „buty”.
	const SKIPPED = ['i', 'a', 'o', 'u', 'w', 'z', 'we', 'ze', 'na', 'do', 'od', 'po', 'za', 'dla', 'lub', 'oraz', 'czy', 'się'];

	function phraseWords(phrase) {
		const all = [...new Set(words(phrase))];
		const meaningful = all.filter((word) => !SKIPPED.includes(word));
		return meaningful.length ? meaningful : all;
	}

	// Ile razy wszystkie słowa frazy padają razem w podanych słowach. Kolejność i odstęp nie mają znaczenia:
	// w treści fraza liczy się, gdy jej słowa stoją w jednym zdaniu.
	function hits(phrase, tokens) {
		return Math.min(...phrase.map((word) => tokens.filter((token) => sameWord(word, token)).length));
	}

	function analyze(input) {
		const phrase = phraseWords(input.phrase);
		if (!phrase.length) {
			return [];
		}
		const has = (text) => hits(phrase, words(text)) > 0;
		const all = words(input.text);
		// Zdanie kończy się przed wielką literą albo nowym wierszem, więc „np.” go nie przerywa.
		const inSentences = (text) => String(text || '').split(/[.!?]+\s+(?=[A-ZĄĆĘŁŃÓŚŹŻ0-9„"])|\n+/)
			.reduce((sum, sentence) => sum + hits(phrase, words(sentence)), 0);
		const count = inSentences(input.text);
		const found = 'Fraza pada w treści ' + count + (count === 1 ? ' raz' : ' razy') + ' (słów w treści: ' + all.length + ').';
		const check = (ok, good, bad) => ({ status: ok ? 'ok' : 'brak', text: ok ? good : bad });
		const headings = (input.headings || []).filter(Boolean);
		let density = { status: 'brak', text: 'Fraza nie pada w treści.' };
		if (count * 100 > all.length * 3) {
			// shortcut: jeden próg (3 razy na 100 słów) dla każdej frazy i długości tekstu; osobne progi, gdy pojawią się fałszywe ostrzeżenia.
			density = { status: 'uwaga', text: found + ' To więcej niż 3 razy na 100 słów, tekst może brzmieć nienaturalnie.' };
		} else if (count) {
			density = { status: 'ok', text: found };
		}
		return [
			check(has(input.title), 'Tytuł SEO zawiera frazę.', 'W tytule SEO brakuje frazy.'),
			check(has(input.description), 'Opis meta zawiera frazę.', 'W opisie meta brakuje frazy.'),
			input.slug === null ? null : check(hits(phrase.map(plain), words(plain(input.slug))) > 0, 'Końcówka adresu zawiera frazę.', 'W końcówce adresu brakuje frazy.'),
			check(inSentences(input.firstParagraph) > 0, 'Fraza pada w pierwszym akapicie.', 'W pierwszym akapicie brakuje frazy.'),
			headings.length
				? check(headings.some(has), 'Fraza pada w śródtytule.', 'Żaden śródtytuł nie zawiera frazy.')
				: { status: 'uwaga', text: 'Treść nie ma śródtytułów.' },
			density,
		].filter(Boolean);
	}

	const api = { stem, sameWord, analyze };
	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	} else {
		root.dxsmKeyphrase = api;
	}
})(typeof window !== 'undefined' ? window : globalThis);
