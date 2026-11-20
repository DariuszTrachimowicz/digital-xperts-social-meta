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

	// Adresy stron nie mają polskich znaków, więc tematy słów porównujemy bez nich.
	function plain(text) {
		return String(text || '').toLowerCase().replace(/ł/g, 'l').normalize('NFD').replace(/\p{M}/gu, '');
	}

	// Końcówki, które w adresie tracą polski znak i nie są już rozpoznawane przez stem(): agentow, opisac.
	const PLAIN_ENDINGS = ['owac', 'ywac', 'iwac', 'ow', 'ac', 'ic', 'yc'];

	function sameInAddress(phraseWord, addressWord) {
		const cut = PLAIN_ENDINGS.find((ending) => addressWord.endsWith(ending) && addressWord.length - ending.length >= 3);
		const address = new Set([...keys(addressWord), ...(cut ? keys(addressWord.slice(0, -cut.length)) : [])].map(plain));
		// Słowo frazy pozbawione znaków przed odmianą (sąd, sad) albo po niej (agentów, agent): wystarczy jedno trafienie.
		return sameWord(plain(phraseWord), addressWord) || [...keys(phraseWord)].some((key) => address.has(plain(key)));
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
	function hits(phrase, tokens, same = sameWord) {
		return Math.min(...phrase.map((word) => tokens.filter((token) => same(word, token)).length));
	}

	// Zdanie kończy się przed wielką literą albo nowym wierszem, więc „np.” go nie przerywa.
	// Skróty, po których zdanie biegnie dalej, tracą kropkę przed podziałem („np. BLIK”). „itd.” i „itp.” zostają, bo zwykle kończą zdanie.
	function sentences(text) {
		return String(text || '').replace(/(^|[^a-ząćęłńóśźż])(np|tzw|tj|tzn|m\.in|ul|al|dr|prof|inż|mgr|wg|nr|godz)\.(?=\s)/gi, '$1$2').split(/[.!?]+\s+(?=[A-ZĄĆĘŁŃÓŚŹŻ0-9„"])|\n+/);
	}

	// Podpowiedzi linków wewnętrznych: fraza kluczowa innej treści pada w tym tekście, a jej słowa stoją obok siebie.
	// shortcut: między słowami frazy może stać jedno obce słowo; szersze okno dawało przypadkowe trafienia, poszerzyć przy skargach na brak podpowiedzi.
	function linkSuggestions(text, candidates, limit = 5) {
		const parts = sentences(text).map((sentence) => ({ sentence: sentence.replace(/\s+/g, ' ').trim(), tokens: words(sentence) }));
		const found = [];
		for (const candidate of candidates) {
			const phrase = phraseWords(candidate.phrase);
			if (!phrase.length) {
				continue;
			}
			const matches = parts.filter(({ tokens }) => tokens.some((token, start) => {
				const span = tokens.slice(start, start + phrase.length + 1);
				return phrase.every((word) => span.some((other) => sameWord(word, other)));
			}));
			if (matches.length) {
				found.push({ ...candidate, count: matches.length, sentence: matches[0].sentence });
			}
		}
		return found.sort((first, second) => second.count - first.count).slice(0, limit);
	}

	function analyze(input) {
		const phrase = phraseWords(input.phrase);
		if (!phrase.length) {
			return [];
		}
		const has = (text) => hits(phrase, words(text)) > 0;
		const all = words(input.text);
		const inSentences = (text) => sentences(text).reduce((sum, sentence) => sum + hits(phrase, words(sentence)), 0);
		const count = inSentences(input.text);
		const found = 'Fraza pada w treści ' + count + (count === 1 ? ' raz' : ' razy') + ' (słów w treści: ' + all.length + ').';
		const check = (ok, good, bad) => ({ status: ok ? 'ok' : 'brak', text: ok ? good : bad });
		const headings = (input.headings || []).filter(Boolean);
		let density = has(input.text)
			? { status: 'uwaga', text: 'Słowa frazy padają w treści, ale ani razu w jednym zdaniu.' }
			: { status: 'brak', text: 'Fraza nie pada w treści.' };
		if (count * 100 > all.length * 3) {
			// shortcut: jeden próg (3 razy na 100 słów) dla każdej frazy i długości tekstu; osobne progi, gdy pojawią się fałszywe ostrzeżenia.
			density = { status: 'uwaga', text: found + ' To więcej niż 3 razy na 100 słów, tekst może brzmieć nienaturalnie.' };
		} else if (count) {
			density = { status: 'ok', text: found };
		}
		return [
			check(has(input.title), 'Tytuł SEO zawiera frazę.', 'W tytule SEO brakuje frazy.'),
			check(has(input.description), 'Opis meta zawiera frazę.', 'W opisie meta brakuje frazy.'),
			input.slug === null ? null : check(hits(phrase, words(plain(input.slug)), sameInAddress) > 0, 'Końcówka adresu zawiera frazę.', 'W końcówce adresu brakuje frazy.'),
			check(has(input.firstParagraph), 'Fraza pada w pierwszym akapicie.', 'W pierwszym akapicie brakuje frazy.'),
			headings.length
				? check(headings.some(has), 'Fraza pada w śródtytule.', 'Żaden śródtytuł nie zawiera frazy.')
				: { status: 'uwaga', text: 'Treść nie ma śródtytułów.' },
			density,
		].filter(Boolean);
	}

	// Sylaby liczymy po samogłoskach; „i” przed samogłoską tylko zmiękcza (nie, ziemia).
	// shortcut: dwugłoski z wyrazów obcych (au, eu) liczą się jako dwie sylaby; słownik, gdy wskaźnik mglistości zacznie wyraźnie zawyżać.
	function syllables(word) {
		const vowels = word.toLowerCase().replace(/i(?=[aeouyąęó])/g, '').match(/[aeiouyąęó]/g);
		return vowels ? vowels.length : 1;
	}

	// Progi długości zdań, akapitów i fragmentów bez śródtytułu są takie jak w popularnych analizatorach SEO (20, 150 i 300 słów).
	// Mglistość to indeks FOG w polskiej wersji: 0,4 × (średnia długość zdania + odsetek słów o co najmniej czterech sylabach).
	function readability(chunks) {
		// Pojedyncze przejście do nowego wiersza w akapicie (edytor klasyczny) nie kończy zdania.
		const paragraphs = chunks.filter((chunk) => !chunk.heading).map((chunk) => chunk.text.replace(/\s*\n\s*/g, ' '));
		const all = paragraphs.flatMap(words);
		if (all.length < 50) {
			return [{ status: 'uwaga', text: 'Za mało tekstu, żeby ocenić czytelność (mniej niż 50 słów).' }];
		}
		const parts = paragraphs.flatMap(sentences).map(words).filter((tokens) => tokens.length);
		const long = parts.filter((tokens) => tokens.length > 20).length;
		const share = Math.round((100 * long) / parts.length);
		const longest = Math.max(...parts.map((tokens) => tokens.length));
		const bigParagraphs = paragraphs.filter((text) => words(text).length > 150).length;
		let section = 0;
		let current = 0;
		for (const chunk of chunks) {
			current = chunk.heading ? 0 : current + words(chunk.text).length;
			section = Math.max(section, current);
		}
		const hard = all.filter((word) => /[a-ząćęłńóśźż]/.test(word) && syllables(word) >= 4).length;
		const fog = Math.round(4 * (all.length / parts.length + (100 * hard) / all.length)) / 10;
		const level = fog < 7 ? 'bardzo prosty' : fog < 10 ? 'prosty' : fog < 13 ? 'na poziomie szkoły średniej' : fog < 18 ? 'na poziomie studiów' : 'specjalistyczny';
		let repeats = 0;
		parts.forEach((tokens, index) => {
			if (index > 1 && tokens[0] === parts[index - 1][0] && tokens[0] === parts[index - 2][0] && (index < 3 || parts[index - 3][0] !== tokens[0])) {
				repeats++;
			}
		});
		const check = (ok, good, bad) => ({ status: ok ? 'ok' : 'uwaga', text: ok ? good : bad });
		const number = (value) => String(value).replace('.', ',');
		return [
			check(long * 4 <= parts.length, 'Zdania: ' + share + ' % ma ponad 20 słów, najdłuższe ma ' + longest + '.', 'Zdania: ' + share + ' % ma ponad 20 słów, najdłuższe ma ' + longest + '. Skróć je, żeby długich było najwyżej 25 %.'),
			check(!bigParagraphs, 'Akapity: żaden nie ma ponad 150 słów.', 'Akapity z ponad 150 słowami: ' + bigParagraphs + '. Podziel je na krótsze.'),
			check(section <= 300, 'Śródtytuły: najdłuższy fragment bez śródtytułu (słów: ' + section + ').', 'Śródtytuły: najdłuższy fragment bez śródtytułu (słów: ' + section + '). Dodaj śródtytuł, żeby żaden fragment nie miał ponad 300 słów.'),
			check(fog < 13, 'Mglistość (FOG): ' + number(fog) + ', tekst ' + level + '.', 'Mglistość (FOG): ' + number(fog) + ', tekst ' + level + '. Skróć zdania i zamień długie słowa na prostsze.'),
			check(!repeats, 'Początki zdań: bez powtórzeń trzy razy z rzędu.', 'Początki zdań: ' + repeats + (repeats === 1 ? ' raz' : ' razy') + ' trzy kolejne zdania zaczynają się tym samym słowem.'),
		];
	}

	// Styl: ślady pisania przez AI i urzędowe nawyki, które da się policzyć bez słownika.
	// Progi kontrastów, słów-wytrychów i strony biernej rosną z długością tekstu: 3 trafienia, a powyżej 2000 słów 1,5 trafienia na każde 1000 słów.
	const FILLERS = ['kluczowy', 'istotny', 'dedykowany', 'kompleksowy', 'innowacyjny', 'holistyczny', 'finalnie', 'ponadto'];
	const LETTER = '[a-ząćęłńóśźż]';
	const DASHES = new RegExp('[' + String.fromCharCode(8211, 8212) + ']', 'g');
	const CONTRASTS = new RegExp(',\\s+a nie\\s|(^|[^a-ząćęłńóśźż])nie tylko\\s[^.!?]*?,\\s+(ale|lecz)\\s', 'gi');
	const OPENERS = new RegExp('(^|[^a-ząćęłńóśźż])(warto (zauważyć|podkreślić|pamiętać|wspomnieć|zaznaczyć)|należy (pamiętać|podkreślić|zauważyć)|nie ulega wątpliwości|w dzisiejszych czasach|w dobie|co więcej|podsumowując|nie da się ukryć)(?!' + LETTER + ')', 'gi');
	// Imiesłów może stać do dwóch słów za „został” („została wczoraj podpisana”). Końcówki są długie, żeby „zostały nam dwa dni” nie liczyło się jako strona bierna.
	const PASSIVE = new RegExp('(^|[^a-ząćęłńóśźż])(został[aoy]?|zostali|zostanie|zostaną|zostać)\\s+(?:' + LETTER + '+\\s+){0,2}?' + LETTER + '{3,}(any|ana|ane|ani|ony|ona|one|eni|ęty|ęta|ęte|ęci|ty|ta|te)(?!' + LETTER + ')', 'gi');

	function style(text) {
		// Każdy odstęp, także spacja nierozdzielająca po „a” i „w”, staje się zwykłą spacją, żeby wzorce jej nie gubiły.
		const source = String(text || '').replace(/\s+/g, ' ');
		const tokens = words(source);
		if (tokens.length < 50) {
			return [{ status: 'uwaga', text: 'Za mało tekstu, żeby ocenić styl (mniej niż 50 słów).' }];
		}
		const count = (pattern) => (source.match(pattern) || []).length;
		const fillers = FILLERS.map((filler) => [filler, tokens.filter((token) => sameWord(filler, token)).length]).filter(([, hits]) => hits).sort((first, second) => second[1] - first[1]);
		const fillerCount = fillers.reduce((sum, [, hits]) => sum + hits, 0);
		const dashes = count(DASHES);
		const contrasts = count(CONTRASTS);
		const openers = count(OPENERS);
		const passive = count(PASSIVE);
		const check = (ok, good, bad) => ({ status: ok ? 'ok' : 'uwaga', text: ok ? good : bad });
		const limit = Math.max(3, Math.round((tokens.length * 1.5) / 1000));
		return [
			check(!dashes, 'Pauzy i półpauzy: brak.', 'Pauzy i półpauzy: ' + dashes + '. Zamień je na przecinek, dwukropek albo kropkę.'),
			check(contrasts < limit, 'Kontrasty „a nie” i „nie tylko, ale”: ' + contrasts + '.', 'Kontrasty „a nie” i „nie tylko, ale”: ' + contrasts + '. Napisz wprost, co jest, bez zaprzeczania.'),
			check(fillerCount < limit, 'Słowa-wytrychy: ' + fillerCount + '.', 'Słowa-wytrychy: ' + fillers.map(([filler, hits]) => filler + ' ×' + hits).join(', ') + '. Zamień je na konkret.'),
			check(!openers, 'Rozbiegi typu „warto zauważyć”: brak.', 'Rozbiegi typu „warto zauważyć”: ' + openers + '. Zacznij zdanie od treści.'),
			check(passive < limit, 'Strona bierna z „został”: ' + passive + '.', 'Strona bierna z „został”: ' + passive + '. Napisz, kto co robi.'),
		];
	}

	const api = { stem, sameWord, analyze, linkSuggestions, readability, syllables, style };
	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	} else {
		root.dxsmKeyphrase = api;
	}
})(typeof window !== 'undefined' ? window : globalThis);
