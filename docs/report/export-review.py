#!/usr/bin/env python3
"""Copy the original implementation report and its linked evidence for the site."""
from pathlib import Path
import re
import shutil

ROOT = Path(__file__).resolve().parent
source = ROOT.parent / 'visdoc/briefing/0907_Welcart割引実装'
output = ROOT / 'public/implementation-review'
document = (source / '実装・検証レポート.html').read_text()
github = 'https://github.com/satokupo/welcart-tiered-discounts/blob/9056929086d8b9e9db7379e48bcd75fd4ea457f1/'
for old, new in {
    '../../../SSoT/FUNCTIONAL_SPEC.md': 'docs/SSoT/FUNCTIONAL_SPEC.md',
    '../../../SSoT/INTEGRATIONS.md': 'docs/SSoT/INTEGRATIONS.md',
    '../../../../README.md': 'README.md',
}.items():
    assert document.count(f'href="{old}"') == 1
    document = document.replace(f'href="{old}"', f'href="{github}{new}" target="_blank" rel="noopener noreferrer"')

references = set(re.findall(r'href="((?:evidence|images)/[^"]+)"', document))
assert len(references) == 22, 'Expected 21 evidence logs and one linked image'
for reference in references:
    source_file = (source / reference).resolve(strict=True)
    assert source_file.is_relative_to(source.resolve()), 'Linked asset must stay in the source report'
    destination = output / reference
    destination.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(source_file, destination)
    assert destination.read_bytes() == source_file.read_bytes()
assert not re.search(r'(?:href|src)="(?:\.\./|file:)|/Users/|/Volumes/', document)
(output / 'index.html').write_text(document)
print(f'Packaged original implementation report and {len(references)} linked assets')
