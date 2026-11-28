import { join } from 'node:path';
import { installedFontVersion, scanIconNames } from '../../../testing/icon-names';
import { ICON_INK_SHIFTS, ICON_INK_SHIFTS_FONT_VERSION } from './icon-ink-shifts';

const FRONTEND = join(__dirname, '..', '..', '..', '..');
const STALE = 'Run `npm run icons:measure` from frontend/ to remeasure.';

describe('icon ink shifts', () => {
  it('covers exactly the names the source can render', () => {
    expect({ names: Object.keys(ICON_INK_SHIFTS), fix: STALE }).toEqual({
      names: scanIconNames(FRONTEND),
      fix: STALE,
    });
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
});
