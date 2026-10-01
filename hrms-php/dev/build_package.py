"""DEV ONLY — builds dist/worknest-hostinger.zip, the upload-ready package.

Contents (recommended layout — public_html is the web root, the rest sits
one level above it):
    public_html/   app/   storage/ (empty skeleton)   database/hrms.sql
    database/hrms_demo_data.sql   .env.example   README.md
Never included: .env, dev/, database/tools/, runtime files in storage/.
    python hrms-php/dev/build_package.py
"""
import os, zipfile, hashlib

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DIST = os.path.join(ROOT, 'dist')
OUT = os.path.join(DIST, 'worknest-hostinger.zip')
SKIP_DIRS = {'dev', 'dist', 'tools', '.git'}

def add_tree(z, rel):
    for base, dirs, files in os.walk(os.path.join(ROOT, rel)):
        dirs[:] = sorted(d for d in dirs if d not in SKIP_DIRS)
        for f in sorted(files):
            p = os.path.join(base, f)
            z.write(p, os.path.relpath(p, ROOT).replace(os.sep, '/'))

os.makedirs(DIST, exist_ok=True)
with zipfile.ZipFile(OUT, 'w', zipfile.ZIP_DEFLATED) as z:
    add_tree(z, 'public_html')
    add_tree(z, 'app')
    for f in ('database/hrms.sql', 'database/hrms_demo_data.sql', 'database/.htaccess', '.env.example', 'README.md'):
        if os.path.isfile(os.path.join(ROOT, f)):
            z.write(os.path.join(ROOT, f), f)
    # storage/ skeleton only: protective .htaccess files + empty folders.
    for f in ('storage/.htaccess', 'storage/uploads/.htaccess'):
        z.write(os.path.join(ROOT, f), f)
    for d in ('cache', 'logs', 'sessions'):
        z.writestr(f'storage/{d}/.gitkeep', '')

names = zipfile.ZipFile(OUT).namelist()
bad = [n for n in names if n == '.env' or n.startswith(('dev/', 'database/tools/')) or '/uploads/c' in n]
assert not bad, bad
print(f'{OUT}\n{len(names)} files, {os.path.getsize(OUT) // 1024} KB, sha256 {hashlib.sha256(open(OUT, "rb").read()).hexdigest()[:16]}')
