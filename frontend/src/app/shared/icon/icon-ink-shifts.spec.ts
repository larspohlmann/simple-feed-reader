import { join } from 'node:path';
import { compileScssFile, cssRules } from '../../theme/sass-testing';
import { ICON_VARIATIONS, installedFontVersion, scanIconNames } from '../../../testing/icon-names';
import { ICON_INK_SHIFTS, ICON_INK_SHIFTS_FONT_VERSION } from './icon-ink-shifts';

const FRONTEND = join(__dirname, '..', '..', '..', '..');
const STALE = 'Run `npm run icons:measure` from frontend/ to remeasure.';

describe('icon ink shifts', () => {
  it('covers exactly the names the source can render', () => {
    const scanned = new Set(scanIconNames(FRONTEND));
    const measured = new Set(Object.keys(ICON_INK_SHIFTS));
    const missing = [...scanned].filter((name) => !measured.has(name));
    const stale = [...measured].filter((name) => !scanned.has(name));
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
    const rules = cssRules(compileScssFile(join(__dirname, 'icon.component.scss')));
    const declared = ['span', ':host(.filled) span'].map(
      (selector) =>
        rules.find((rule) => rule.selector === selector)?.declarations['font-variation-settings'],
    );
    expect({ declared, fix: STALE }).toEqual({
      declared: ICON_VARIATIONS.map(({ variation }) => variation),
      fix: STALE,
    });
  });
});
