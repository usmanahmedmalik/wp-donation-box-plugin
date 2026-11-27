#!/usr/bin/env python3
"""Package only distributable plugin files; no credentials, tests, or Git metadata."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED

root = Path(__file__).resolve().parent.parent
output = root / 'dist' / 'pledgly-donations.zip'
output.parent.mkdir(exist_ok=True)
files = [root / name for name in ['pledgly-donations.php', 'README.md', 'readme.txt', 'LICENSE']]
for directory in ['includes', 'assets', 'docs']:
    files.extend(p for p in (root / directory).rglob('*') if p.is_file() and not p.name.startswith('.'))
with ZipFile(output, 'w', ZIP_DEFLATED) as archive:
    for file in sorted(files):
        archive.write(file, 'pledgly-donations/' + str(file.relative_to(root)))
print(output)
