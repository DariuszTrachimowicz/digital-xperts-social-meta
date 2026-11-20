// Fraza kluczowa: node tests/keyphrase.test.js
// odmiana.json: formy z trzech wpisów, których nie było przy pisaniu stemmera, z lematami ze słownika Morfeusz.
const assert = require('assert');
const { sameWord, analyze } = require('../assets/keyphrase.js');

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
console.log('keyphrase: OK');
