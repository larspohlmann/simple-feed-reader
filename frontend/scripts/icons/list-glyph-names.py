#!/usr/bin/env python3
"""Lists every icon name the installed Material Symbols font renders, from its ligature table."""

import json
import re
import sys
from pathlib import Path

try:
    import brotli  # noqa: F401
    from fontTools.ttLib import TTFont
except ImportError as error:
    sys.exit(f'{error}. Install the extractor requirements: pip install fonttools brotli')

FRONTEND = Path(__file__).resolve().parents[2]
PACKAGE = FRONTEND / 'node_modules' / 'material-symbols'
OUTPUT = Path(__file__).resolve().parent / 'glyph-names.txt'
NAME = re.compile(r'^[a-z0-9_]+$')
LIGATURE_LOOKUP = 4
EXTENSION_LOOKUP = 7


def ligature_tables(font: TTFont):
    for lookup in font['GSUB'].table.LookupList.Lookup:
        for table in lookup.SubTable:
            if table.LookupType == EXTENSION_LOOKUP:
                table = table.ExtSubTable
            if table.LookupType == LIGATURE_LOOKUP:
                yield table


def read_glyph_names(font: TTFont) -> list[str]:
    characters = {glyph: chr(code) for code, glyph in font.getBestCmap().items()}
    names = set()
    for table in ligature_tables(font):
        for first, ligatures in table.ligatures.items():
            for ligature in ligatures:
                glyphs = [first, *ligature.Component]
                if all(glyph in characters for glyph in glyphs):
                    names.add(''.join(characters[glyph] for glyph in glyphs))
    return sorted(name for name in names if NAME.match(name))


def main() -> None:
    version = json.loads((PACKAGE / 'package.json').read_text())['version']
    font = TTFont(PACKAGE / 'material-symbols-outlined.woff2')
    names = read_glyph_names(font)
    OUTPUT.write_text(f'# material-symbols {version}\n' + '\n'.join(names) + '\n')
    print(f'{len(names)} names written to {OUTPUT.relative_to(FRONTEND)}')


if __name__ == '__main__':
    main()
