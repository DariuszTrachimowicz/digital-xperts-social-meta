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
assert.strictEqual(analyze(split)[3].status, 'brak');
assert.strictEqual(analyze(split)[5].status, 'uwaga');
console.log('keyphrase: OK');
