import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { ICON_VARIATIONS, installedFontVersion, scanIconNames } from '../../../testing/icon-names';
import { ICON_INK_SHIFTS, ICON_INK_SHIFTS_FONT_VERSION } from './icon-ink-shifts';

const FRONTEND = join(__dirname, '..', '..', '..', '..');
const STALE = 'Run `npm run icons:measure` from frontend/ to remeasure.';

describe('icon ink shifts', () => {
  it('covers exactly the names the source can render', () => {
    const scanned = scanIconNames(FRONTEND);
    const measured = Object.keys(ICON_INK_SHIFTS);
    const missing = scanned.filter((name) => !measured.includes(name));
    const stale = measured.filter((name) => !scanned.includes(name));
    if (missing.length > 0 || stale.length > 0) {
      throw new Error(`${STALE} Missing: ${missing.join(', ')}. Stale: ${stale.join(', ')}.`);
    }
  });

  it('was measured against the installed font', () => {
    expect({ version: ICON_INK_SHIFTS_FONT_VERSION, fix: STALE }).toEqual({
      version: installedFontVersion(FRONTEND),
      fix: STALE,
    });
  });

  it('holds a finite, small shift for every glyph', () => {
    const shifts = Object.values(ICON_INK_SHIFTS).flat();
    expect(shifts.filter((shift) => !Number.isFinite(shift) || Math.abs(shift) >= 0.15)).toEqual(
      [],
    );
  });

  it('lifts the low glyphs and lowers the high ones', () => {
    expect(ICON_INK_SHIFTS['home'][0]).toBeLessThan(0);
    expect(ICON_INK_SHIFTS['chat_bubble'][0]).toBeGreaterThan(0);
  });

  it('measured the font variations icon.component.scss renders with', () => {
    const stylesheet = readFileSync(join(__dirname, 'icon.component.scss'), 'utf8');
    const declared = [...stylesheet.matchAll(/font-variation-settings:([^;]+);/g)].map((match) =>
      match[1].replace(/\s+/g, ' ').trim(),
    );
    expect({ declared, fix: STALE }).toEqual({
      declared: [ICON_VARIATIONS.outlined, ICON_VARIATIONS.filled],
      fix: STALE,
    });
  });
});
