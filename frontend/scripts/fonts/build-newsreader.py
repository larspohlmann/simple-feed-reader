#!/usr/bin/env python3
"""Builds the classic theme's Newsreader faces: sized and seated like the system face."""

import re
import shutil
import sys
from pathlib import Path

from fontTools.ttLib import TTFont

SIZE_MATCH = 1.177
ASCENT = 0.99
DESCENT = 0.22

FRONTEND = Path(__file__).resolve().parents[2]
PACKAGE = FRONTEND / 'node_modules' / '@fontsource-variable' / 'newsreader'
OUTPUT = FRONTEND / 'public' / 'fonts'
FAMILY = 'Newsreader Classic'
FACES = {
    ('latin', 'normal'): 'newsreader-classic-latin-roman.woff2',
    ('latin', 'italic'): 'newsreader-classic-latin-italic.woff2',
    ('latin-ext', 'normal'): 'newsreader-classic-latin-ext-roman.woff2',
    ('latin-ext', 'italic'): 'newsreader-classic-latin-ext-italic.woff2',
}
LINE_METRIC_TAGS = {'hasc', 'hdsc', 'hlgp', 'hcla', 'hcld'}
USE_TYPO_METRICS = 1 << 7


def read_reserved_font_names(licence: str) -> list[str]:
    header = licence.split('This Font Software is licensed', 1)[0]
    return re.findall(r'Reserved Font Name[s]?\s+"?([^",.\n]+)', header)


def scale_em(font: TTFont) -> int:
    head = font['head']
    head.unitsPerEm = round(head.unitsPerEm / SIZE_MATCH)
    return head.unitsPerEm


def seat_line(font: TTFont, units_per_em: int) -> None:
    ascent = round(ASCENT * units_per_em)
    descent = round(DESCENT * units_per_em)
    hhea = font['hhea']
    hhea.ascent, hhea.descent, hhea.lineGap = ascent, -descent, 0
    os2 = font['OS/2']
    os2.sTypoAscender, os2.sTypoDescender, os2.sTypoLineGap = ascent, -descent, 0
    os2.usWinAscent, os2.usWinDescent = ascent, descent
    os2.fsSelection |= USE_TYPO_METRICS


def drop_line_metric_variations(font: TTFont) -> None:
    if 'MVAR' not in font:
        return
    table = font['MVAR'].table
    table.ValueRecord = [record for record in table.ValueRecord if record.ValueTag not in LINE_METRIC_TAGS]
    table.ValueCount = len(table.ValueRecord)
    if not table.ValueRecord:
        del font['MVAR']


def rename_family(font: TTFont) -> None:
    names = font['name']
    full_name = f'{FAMILY} {names.getDebugName(2)}'
    renamed = {1: FAMILY, 16: FAMILY, 21: FAMILY, 4: full_name, 6: full_name.replace(' ', '-', 1).replace(' ', '')}
    for record in names.names:
        if record.nameID in renamed:
            record.string = renamed[record.nameID]


def build_face(source: Path, target: Path, renames: bool) -> None:
    font = TTFont(source, recalcTimestamp=False)
    seat_line(font, scale_em(font))
    drop_line_metric_variations(font)
    if renames:
        rename_family(font)
    font.flavor = 'woff2'
    font.save(target)


def main() -> int:
    licence = (PACKAGE / 'LICENSE').read_text(encoding='utf-8')
    reserved = read_reserved_font_names(licence)
    OUTPUT.mkdir(parents=True, exist_ok=True)
    for (subset, style), name in FACES.items():
        source = PACKAGE / 'files' / f'newsreader-{subset}-opsz-{style}.woff2'
        build_face(source, OUTPUT / name, bool(reserved))
        print(f'{name} <- {source.name}')
    shutil.copyfile(PACKAGE / 'LICENSE', OUTPUT / 'OFL.txt')
    print(f'Reserved Font Names: {reserved or "none"}; internal family {"renamed" if reserved else "kept"}')
    return 0


if __name__ == '__main__':
    sys.exit(main())
