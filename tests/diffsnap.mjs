// Porównanie migawek przed i po: node diffsnap.mjs before.json after.json
import fs from 'node:fs';
const [a, b] = process.argv.slice(2).map(f => JSON.parse(fs.readFileSync(f, 'utf8')));
const norm = u => u.replace(/[?&]nc=\d+/, '');
let same = 0, diff = 0;
for (const [u, x] of Object.entries(a)) {
  const y = b[u]; if (!y) { console.log('BRAK PO', u); diff++; continue; }
  const ok = x.status === y.status && JSON.stringify(x.tags) === JSON.stringify(y.tags) && JSON.stringify(x.ld) === JSON.stringify(y.ld);
  if (ok) { same++; continue; }
  diff++; console.log('!!', u, x.status, '->', y.status);
  x.tags.filter(t => !y.tags.includes(t)).forEach(t => console.log('   - ' + t.slice(0, 160)));
  y.tags.filter(t => !x.tags.includes(t)).forEach(t => console.log('   + ' + t.slice(0, 160)));
  if (JSON.stringify(x.ld) !== JSON.stringify(y.ld)) console.log('   ld', x.ld, '->', y.ld);
}
console.log('identyczne', same, 'różne', diff);
