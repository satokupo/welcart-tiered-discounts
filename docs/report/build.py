#!/usr/bin/env python3
"""Build the static report with the installed Pandoc executable."""
from pathlib import Path
import re
import subprocess
import xml.etree.ElementTree as ET


def label_table(match):
    table = ET.fromstring(match.group())
    labels = ["".join(cell.itertext()).strip() for cell in table.findall("./thead/tr/th")]
    for row in table.findall("./tbody/tr"):
        assert len(row) == len(labels), "Table columns do not match headers"
        for cell, label in zip(row, labels):
            cell.set("data-label", label)
    return ET.tostring(table, encoding="unicode", method="html")

def external_link(match):
    tag = match.group()
    if not re.search(r"\bhref=[\"'](?:https?:)?//", tag, flags=re.I):
        return tag
    tag = re.sub(r"\s+(?:target|rel)=([\"']).*?\1", "", tag, flags=re.I)
    return tag[:-1] + ' target="_blank" rel="noopener noreferrer">'


ROOT = Path(__file__).resolve().parent
template = (ROOT / "template.html").read_text()
for name in ("overview", "design", "ai", "recording"):
    rendered = subprocess.run(
        ["pandoc", str(ROOT / "content" / f"{name}.md"), "--from=gfm",
         "--to=html5", f"--id-prefix={name}-", "--shift-heading-level-by=1"],
        check=True, capture_output=True, text=True,
    ).stdout
    rendered = re.sub(r'href="#' + name + r'-(plugin-main|plugin-dev|design|ai|recording)"', r'href="#\1"', rendered)
    rendered = re.sub(r"<table\b[^>]*>.*?</table>", label_table, rendered, flags=re.S)
    template = template.replace("{{" + name + "}}", rendered)
assert "{{" not in template, "Unresolved content slot"
template = re.sub(r"<a\b[^>]*>", external_link, template)
(ROOT / "public" / "index.html").write_text(template)
print("Built docs/report/public/index.html")
