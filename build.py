"""Buduje paczkę wydania: python build.py -> dist/digital-xperts-social-meta-<wersja>.zip
Wydanie: gh release create v<wersja> dist/digital-xperts-social-meta-<wersja>.zip --title <wersja> --notes "..."
"""
import os, re, zipfile, hashlib

root = os.path.dirname(os.path.abspath(__file__))
slug = 'digital-xperts-social-meta'
version = re.search(r"const VERSION = '([\d.]+)';", open(os.path.join(root, slug + '.php'), encoding='utf-8').read()).group(1)
os.makedirs(os.path.join(root, 'dist'), exist_ok=True)
out = os.path.join(root, 'dist', f'{slug}-{version}.zip')
skip_dirs = {'.git', '.github', 'tests', 'dist'}
skip_files = {'build.py', '.gitignore', 'AGENTS.md', 'CLAUDE.md', 'README.md'}
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
    for base, dirs, files in os.walk(root):
        dirs[:] = sorted(d for d in dirs if d not in skip_dirs)
        for name in sorted(files):
            if name in skip_files:
                continue
            path = os.path.join(base, name)
            z.write(path, slug + '/' + os.path.relpath(path, root).replace(os.sep, '/'))
print(out, hashlib.sha256(open(out, 'rb').read()).hexdigest())
