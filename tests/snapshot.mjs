// Migawka nagłówków strony do porównania przed i po wdrożeniu: node snapshot.mjs https://domena plik.json [limit]
import fs from 'node:fs';
const [base, out, limit = '40'] = process.argv.slice(2);
const UA = { 'user-agent': 'Mozilla/5.0 dxsm-snapshot' };
const get = async u => { const r = await fetch(u, { headers: UA, redirect: 'follow' }); return { s: r.status, t: await r.text() }; };
let urls = [base + '/'];
for (const sm of ['/wp-sitemap.xml', '/sitemap_index.xml']) {
  const { s, t } = await get(base + sm); if (s !== 200) continue;
  const locs = [...t.matchAll(/<loc>([^<]+)<\/loc>/g)].map(m => m[1].trim());
  for (const x of locs.filter(l => /\.xml/.test(l)).slice(0, 10)) { const r = await get(x); urls.push(...[...r.t.matchAll(/<loc>([^<]+)<\/loc>/g)].map(m => m[1].trim())); }
  urls.push(...locs.filter(l => !/\.xml/.test(l)));
  break;
}
urls = [...new Set(urls)].slice(0, +limit);
const snap = {};
for (const u of urls) {
  const { s, t } = await get(u + (u.includes('?') ? '&' : '?') + 'nc=' + Date.now());
  const head = t.slice(0, Math.max(0, t.indexOf('</head>')));
  const tags = (head.match(/<title>[^<]*<\/title>|<meta[^>]*(?:name|property)=["'](?:description|og:[^"']+|twitter:[^"']+|robots)["'][^>]*>|<link[^>]*rel=["']canonical["'][^>]*>/g) || []).map(x => x.replace(/\s*\/?>$/, '>').replace(/'/g, '"'));
  const ld = [...head.matchAll(/<script[^>]*application\/ld\+json[^>]*>([\s\S]*?)<\/script>/g)].map(m => { try { return JSON.stringify(JSON.parse(m[1])).length; } catch { return -1; } });
  snap[u] = { status: s, tags, ld };
}
fs.writeFileSync(out, JSON.stringify(snap, null, 1));
console.log(out, Object.keys(snap).length, 'adresów');
