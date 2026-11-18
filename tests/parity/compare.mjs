// Wyciąga z nagłówka tytuł, meta (name/property) i liczbę bloków JSON-LD, żeby porównać dwa stany strony.
const urls = JSON.parse(process.argv[2]);
const out = {};
for (const [k, u] of Object.entries(urls)) {
  const h = await (await fetch(u.replace('http://127.0.0.1:8096', 'http://127.0.0.1:8096'))).text();
  const head = h.slice(0, h.indexOf('</head>'));
  const tags = (head.match(/<title>[^<]*<\/title>|<meta[^>]*(?:name|property)=["'][^"']+["'][^>]*>/g) || [])
    .map(t => t.replace(/\s+\/?>$/, '>').replace(/'/g, '"'))
    .filter(t => !/name="(generator|robots|viewport)"/.test(t));
  out[k] = { tags, ldjson: (head.match(/application\/ld\+json/g) || []).length };
}
console.log(JSON.stringify(out, null, 1));
