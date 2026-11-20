// Fraza kluczowa: node tests/keyphrase.test.js
// odmiana.json: formy z trzech wpisów, których nie było przy pisaniu stemmera, z lematami ze słownika Morfeusz.
const assert = require('assert');
const { sameWord, analyze, linkSuggestions, readability, syllables } = require('../assets/keyphrase.js');

const score = {};
for (const [form, lemma, kind] of require('./odmiana.json')) {
	if (form === lemma) continue;
	score[kind] = score[kind] || [0, 0];
	score[kind][0] += sameWord(lemma, form);
	score[kind][1]++;
}
const share = (kind) => score[kind][0] / score[kind][1];
console.log(Object.fromEntries(Object.keys(score).map((kind) => [kind, Math.round(100 * share(kind)) + ' %'])));
assert(share('rzecz') >= 0.97, 'rzeczowniki');
assert(share('przym') >= 0.87, 'przymiotniki');
assert(share('czas') >= 0.65, 'czasowniki');

for (const [a, b] of [['usługa', 'usłudze'], ['klient', 'klienci'], ['źródło', 'źródeł'], ['wyszukiwarka', 'wyszukiwarek'], ['format', 'formacie'], ['WordPress', 'wordpressa']]) {
	assert(sameWord(a, b), a + ' ' + b);
}
for (const [a, b] of [['brat', 'brak'], ['strona', 'struna'], ['sklep', 'sklepikarz'], ['seo', 'sen']]) {
	assert(!sameWord(a, b), a + ' ' + b);
}

const input = {
	phrase: 'usługi księgowe',
	title: 'Usługa księgowa dla małych firm',
	description: 'Biuro w Warszawie.',
	slug: 'uslugi-ksiegowe-warszawa',
	firstParagraph: 'Szukasz usług księgowych? Dobrze trafiłeś.',
	headings: ['Cennik', 'Kontakt'],
	text: 'Szukasz usług księgowych? Dobrze trafiłeś.\nCennik\nO usłudze księgowej opowiemy przez telefon. Księgowa oddzwoni.',
};
assert.deepStrictEqual(analyze(input).map((check) => check.status), ['ok', 'brak', 'ok', 'ok', 'brak', 'uwaga']);
assert.strictEqual(analyze(input)[5].text, 'Fraza pada w treści 2 razy (słów w treści: 14). To więcej niż 3 razy na 100 słów, tekst może brzmieć nienaturalnie.');
assert.deepStrictEqual(analyze({ ...input, phrase: ' ' }), []);
assert.strictEqual(analyze({ ...input, text: '', headings: [] })[5].text, 'Fraza nie pada w treści.');
assert.strictEqual(analyze({ ...input, headings: [] })[4].status, 'uwaga');
const long = analyze({ ...input, text: input.text + ' słowo'.repeat(200) })[5];
assert.deepStrictEqual(long, { status: 'ok', text: 'Fraza pada w treści 2 razy (słów w treści: 214).' });
assert.strictEqual(analyze({ ...input, phrase: 'marketing AI', title: 'Marketing' })[0].status, 'brak');
assert.strictEqual(analyze({ ...input, phrase: 'sklep z butami', title: 'Buty w naszym sklepie' })[0].status, 'ok');
assert.strictEqual(analyze({ ...input, slug: null }).length, 5);
const split = { ...input, firstParagraph: 'Oferujemy usługi. Księgowe pracują tutaj.', text: 'Usługi świadczy np. biuro księgowe.' };
assert.strictEqual(analyze(split)[3].status, 'ok');
assert.strictEqual(analyze(split)[5].status, 'uwaga');
assert.deepStrictEqual(analyze({ ...split, text: 'Oferujemy usługi. Księgowe pracują tutaj.' })[5], { status: 'uwaga', text: 'Słowa frazy padają w treści, ale ani razu w jednym zdaniu.' });
// Adres bez polskich znaków: agentów i agenci, opis i opisać, usługi i usługę.
assert.strictEqual(analyze({ ...input, phrase: 'strona dla agentów AI', slug: 'strona-internetowa-agenci-ai-audyt' })[2].status, 'ok');
assert.strictEqual(analyze({ ...input, phrase: 'opis usługi', slug: 'jak-opisac-usluge-zeby-klient-rozumial' })[2].status, 'ok');
assert.strictEqual(analyze({ ...input, phrase: 'źródła wiedzy', slug: 'zrodlo-wiedzy' })[2].status, 'ok');
assert.strictEqual(analyze({ ...input, phrase: 'praca zdalna', slug: 'prawo-pracy' })[2].status, 'brak');
assert.strictEqual(analyze({ ...input, phrase: 'sąd pracy', slug: 'sad-pracy' })[2].status, 'ok');
assert.strictEqual(analyze({ ...input, phrase: 'zarządzanie projektami', slug: 'zarzadzanie-projektami' })[2].status, 'ok');
// Każde słowo z zestawu musi pasować do własnego adresu bez polskich znaków.
const bare = (word) => word.replace(/ł/g, 'l').normalize('NFD').replace(/\p{M}/gu, '');
const lost = require('./odmiana.json').filter(([form]) => analyze({ ...input, phrase: form, slug: bare(form) })[2].status !== 'ok');
assert.deepStrictEqual(lost, []);
assert.strictEqual(analyze({ ...input, phrase: 'serwis Citroen', slug: 'serwis-citroën' })[2].status, 'ok');
assert.strictEqual(analyze({ ...input, phrase: 'płatności BLIK', text: 'Akceptujemy płatności mobilne, np. BLIK. Inne też.' })[5].status, 'uwaga');
assert.ok(analyze({ ...input, phrase: 'płatności BLIK', text: 'Akceptujemy płatności mobilne, np. BLIK. Inne też.' })[5].text.startsWith('Fraza pada w treści 1 raz'));
assert.strictEqual(analyze({ ...input, phrase: 'serwis laptopa', text: 'Naprawiamy laptopy, monitory itp. Serwis jest czynny w soboty.' })[5].text, 'Słowa frazy padają w treści, ale ani razu w jednym zdaniu.');
// Podpowiedzi linków: słowa frazy muszą stać obok siebie (najwyżej jedno obce słowo między nimi).
const others = [
	{ title: 'Strona usługi', url: '/strona-uslugi/', phrase: 'strona usługi' },
	{ title: 'Kopia zapasowa', url: '/kopia/', phrase: 'kopia zapasowa strony' },
	{ title: 'Budżet', url: '/budzet/', phrase: 'budżet Google Ads' },
	{ title: 'Bez frazy', url: '/pusty/', phrase: ' ' },
];
const article = 'Dla strony usługi przygotuj zakres. Na stronach każdej usługi dopisz cenę. Sprawdź stronę umowy, warunki i regulamin usługi. Rób też kopię zapasową całej strony.';
const hints = linkSuggestions(article, others);
assert.deepStrictEqual(hints.map((hint) => [hint.url, hint.count]), [['/strona-uslugi/', 2], ['/kopia/', 1]]);
assert.strictEqual(hints[0].sentence, 'Dla strony usługi przygotuj zakres');
assert.strictEqual(linkSuggestions(article, others, 1).length, 1);
assert.deepStrictEqual(linkSuggestions('', others), []);
// Czytelność: sylaby, długość zdań i akapitów, fragmenty bez śródtytułu, mglistość, powtórzone początki zdań.
assert.deepStrictEqual(['kot', 'nie', 'ziemia', 'źródło', 'wyszukiwarka', 'pozycjonowanie', 'SEO', 'xyz'].map(syllables), [1, 1, 2, 2, 5, 6, 2, 1]);
assert.deepStrictEqual(readability([{ heading: false, text: 'Krótki tekst.' }]), [{ status: 'uwaga', text: 'Za mało tekstu, żeby ocenić czytelność (mniej niż 50 słów).' }]);
const simple = { heading: false, text: 'Ala ma kota. Kot ma dom. Dom jest mały. Pies też tu jest. ' .repeat(5) };
const easy = readability([{ heading: true, text: 'Dom' }, simple, simple]);
assert.deepStrictEqual(easy.map((check) => check.status), ['ok', 'ok', 'ok', 'ok', 'ok']);
assert.strictEqual(easy[0].text, 'Zdania: 0 % ma ponad 20 słów, najdłuższe ma 4.');
assert.strictEqual(easy[2].text, 'Śródtytuły: najdłuższy fragment bez śródtytułu (słów: 130).');
assert.strictEqual(easy[3].text, 'Mglistość (FOG): 1,3, tekst bardzo prosty.');
const dense = 'Automatyzacja procesów pozycjonowania wymaga wielokrotnego porównywania charakterystycznych wskaźników organizacyjnych oraz systematycznego dokumentowania wszystkich zaobserwowanych nieprawidłowości administracyjnych, ponieważ niekontrolowane modyfikacje konfiguracji powodują nieprzewidywalne konsekwencje wizerunkowe. ';
const hardText = readability([{ heading: false, text: dense.repeat(8) }]);
assert.deepStrictEqual(hardText.map((check) => check.status), ['uwaga', 'uwaga', 'ok', 'uwaga', 'uwaga']);
assert.ok(hardText[3].text.includes('tekst specjalistyczny'));
assert.strictEqual(hardText[4].text, 'Początki zdań: 1 raz trzy kolejne zdania zaczynają się tym samym słowem.');
const noHeadings = readability([simple, simple, simple, simple, simple]);
assert.strictEqual(noHeadings[2].status, 'uwaga');
assert.strictEqual(readability([simple, simple, { heading: true, text: 'Dalej' }, simple, simple, simple])[2].text, 'Śródtytuły: najdłuższy fragment bez śródtytułu (słów: 195).');
// Akapit złamany pojedynczymi wierszami daje ten sam wynik co akapit w jednym wierszu.
const wrapped = 'Automatyzacja procesów wymaga porównywania wskaźników organizacyjnych oraz dokumentowania zaobserwowanych nieprawidłowości, ponieważ modyfikacje konfiguracji powodują konsekwencje wizerunkowe dla całej firmy i jej klientów w kolejnych miesiącach pracy. Krótkie zdanie na koniec.';
const oneLine = [{ heading: false, text: (wrapped + ' ').repeat(4) }];
const manyLines = [{ heading: false, text: (wrapped.replace(/ (?=wskaźników|konsekwencje|Krótkie)/g, '\n') + '\n').repeat(4) }];
assert.deepStrictEqual(readability(manyLines), readability(oneLine));
// Próg 25 % liczony bez zaokrąglenia: 17 długich zdań na 67 to już za dużo.
const longOne = 'słowo '.repeat(21).trim() + '. ';
const shortOne = 'Ala ma kota. ';
assert.strictEqual(readability([{ heading: false, text: 'Start. ' + (longOne.replace('słowo', 'Słowo')).repeat(17) + shortOne.repeat(49) }])[0].status, 'uwaga');
// Styl: pauzy, kontrasty, słowa-wytrychy w odmianie, rozbiegi, strona bierna.
const { style } = require('../assets/keyphrase.js');
assert.deepStrictEqual(style('Krótki tekst.'), [{ status: 'uwaga', text: 'Za mało tekstu, żeby ocenić styl (mniej niż 50 słów).' }]);
const filler = 'Ala ma kota, a kot ma dom. Pies śpi w budzie obok domu. ';
const plainStyle = style(filler.repeat(6));
assert.deepStrictEqual(plainStyle.map((check) => check.status), ['ok', 'ok', 'ok', 'ok', 'ok']);
assert.strictEqual(plainStyle[2].text, 'Słowa-wytrychy: 0.');
const pause = String.fromCharCode(8212);
const marked = style(filler.repeat(5) + 'To kluczowy krok ' + pause + ' i kluczowa zmiana, a nie drobiazg. Warto zauważyć, że dedykowane narzędzie zostało wdrożone, a nie kupione. '
	+ 'Nie tylko planujemy, ale też liczymy. Raport został wysłany. Plik zostanie sprawdzony. Co więcej, istotnych uwag brak. Zostały nam dwa dni.');
assert.deepStrictEqual(marked.map((check) => check.status), ['uwaga', 'uwaga', 'uwaga', 'uwaga', 'uwaga']);
assert.strictEqual(marked[0].text, 'Pauzy i półpauzy: 1. Zamień je na przecinek, dwukropek albo kropkę.');
assert.ok(marked[1].text.startsWith('Kontrasty „a nie” i „nie tylko, ale”: 3.'));
assert.strictEqual(marked[2].text, 'Słowa-wytrychy: kluczowy ×2, istotny ×1, dedykowany ×1. Zamień je na konkret.');
assert.ok(marked[3].text.startsWith('Rozbiegi typu „warto zauważyć”: 2.'));
assert.ok(marked[4].text.startsWith('Strona bierna z „został”: 3.'));
const passive = style(filler.repeat(6) + 'Projekt został już zatwierdzony. Umowa została wczoraj podpisana. Środki zostały w całości przyznane. Zostały nam dwa dni. W piątek odbędzie się spotkanie podsumowujące rok szkolny.');
assert.ok(passive[4].text.startsWith('Strona bierna z „został”: 3.'));
assert.strictEqual(passive[3].status, 'ok');
// Spacja nierozdzielająca po „a” i w rozbiegu daje ten sam wynik co zwykła.
const hard = String.fromCharCode(160);
const spaced = filler.repeat(6) + 'Wybierz plik, a nie folder. Podaj datę, a nie godzinę. Zapisz numer, a nie nazwę. Warto zauważyć resztę.';
assert.ok(style(spaced)[1].text.startsWith('Kontrasty „a nie” i „nie tylko, ale”: 3.'));
assert.deepStrictEqual(style(spaced.replace(/, a nie/g, ', a' + hard + 'nie').replace('Warto zauważyć', 'Warto' + hard + 'zauważyć')), style(spaced));
console.log('keyphrase: OK');
