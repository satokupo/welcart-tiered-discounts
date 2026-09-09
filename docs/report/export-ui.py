#!/usr/bin/env python3
"""Package the final UI comparison HTML with its local styles, script and images."""
import base64
import html
import mimetypes
from pathlib import Path
import re
import sys

source = Path(sys.argv[1]).resolve(strict=True)
document = source.read_text()


def local_file(reference):
    path = source.parent / html.unescape(reference)
    return path.resolve(strict=True)


def stylesheet(match):
    css = local_file(match[1]).read_text()
    # The existing system-font fallbacks keep this archive usable offline.
    css = re.sub(r"@import\s+url\([^)]*\)\s*;", "", css)
    assert not re.search(r"url\(", css), "Unexpected stylesheet resource"
    return "<style>" + css + "</style>"


document = re.sub(r'<link rel="stylesheet" href="([^"]+)">', stylesheet, document)
document = re.sub(
    r'<script src="([^"]+)" defer></script>',
    lambda match: "<script>" + local_file(match[1]).read_text() + "</script>",
    document,
)
images = re.findall(r'<img\s+src="([^"]+)"', document)
assert len(images) == 8, "Expected four before/after image pairs"
for number, reference in enumerate(images, 1):
    path = local_file(reference)
    mime = mimetypes.guess_type(path.name)[0]
    assert mime in ("image/png", "image/jpeg"), "Unexpected image type"
    data = base64.b64encode(path.read_bytes()).decode("ascii")
    document = document.replace(f'src="{reference}"', f'id="ui-image-{number}" src="data:{mime};base64,{data}"')
    document = document.replace(f'href="{reference}"', f'href="#ui-image-{number}"')

# Keep the version history text, without shipping or linking to older drafts.
document, count = re.subn(r'<a\s+href="07-162102_[^"]+">UI比較v5</a>', 'UI比較v5（旧版は本資料に含めていません）', document)
assert count == 1
document = re.sub(
    r'<a\s+href="(https://[^"]+)">',
    r'<a href="\1" target="_blank" rel="noopener noreferrer">',
    document,
)
# A same-file fragment opens embedded images without blocked data-URL navigation.
document = document.replace("</body>", '''<script id="image-view">
const selectedImage = /^#ui-image-[1-8]$/.test(location.hash)
  ? document.getElementById(location.hash.slice(1)) : null;
if (selectedImage) {
  const fullImage = selectedImage.cloneNode();
  document.title = fullImage.alt;
  fullImage.style.cssText = 'display:block;max-width:none;width:auto;height:auto;margin:0';
  document.body.style.cssText = 'margin:0;padding:0;max-width:none';
  document.body.replaceChildren(fullImage);
}
</script>
</body>''')
assert '/Users/' not in document and '/Volumes/' not in document
output = Path(__file__).resolve().parent / 'public' / 'ui-comparison.html'
output.write_text(document)
print(f'Packaged {len(images)} images: {output.name} ({output.stat().st_size:,} bytes)')
