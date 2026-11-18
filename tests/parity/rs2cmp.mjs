// Pełny stan SEO dla porównania RS2: tytuł, meta, robots, canonical, JSON-LD (treść), mapy witryny.
const urls = JSON.parse(process.argv[2]);
const out = {};
for (const [k, u] of Object.entries(urls)) {
  const t = await (await fetch(u, { redirect: 'manual' })).text();
  if (k.startsWith('sm')) { out[k] = [...t.matchAll(/<loc>([^<]+)<\/loc>/g)].map(m => m[1]); continue; }
  const head = t.slice(0, t.indexOf('</head>'));
  out[k] = {
    tags: (head.match(/<title>[^<]*<\/title>|<meta[^>]*(?:name|property)=["'](?:description|robots|og:[^"']+|twitter:[^"']+)["'][^>]*>|<link[^>]*rel=["']canonical["'][^>]*>/g) || []).map(x => x.replace(/\s*\/?>$/, '>').replace(/'/g, '"')),
    ld: [...head.matchAll(/<script[^>]*application\/ld\+json[^>]*>([\s\S]*?)<\/script>/g)].map(m => m[1].trim()),
  };
}
console.log(JSON.stringify(out, null, 1));
