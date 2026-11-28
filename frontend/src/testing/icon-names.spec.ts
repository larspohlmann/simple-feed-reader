import { join } from 'node:path';
import { TAG_ICONS } from '../app/shared/icon-choices';
import {
  fontGlyphNames,
  glyphNamesFontVersion,
  iconSourceFiles,
  installedFontVersion,
  scanIconNames,
} from './icon-names';

const FRONTEND = join(__dirname, '..', '..');
const SPEC_ONLY_GLYPH = 'mark_email_unread';

describe('scanIconNames', () => {
  const names = scanIconNames(FRONTEND);

  it('finds names picked in TypeScript and in templates', () => {
    expect(names).toEqual(expect.arrayContaining(['expand_more', 'chevron_right', 'chat_bubble']));
  });

  it('finds every user-choosable tag icon', () => {
    expect(names).toEqual(expect.arrayContaining(TAG_ICONS));
  });

  it('keeps only names the font has', () => {
    const font = fontGlyphNames(FRONTEND);
    expect(names.filter((name) => !font.has(name))).toEqual([]);
  });

  it('reads neither specs, test helpers nor the generated table', () => {
    const files = iconSourceFiles(FRONTEND);
    expect(files).toContain('app/shared/icon-choices.ts');
    expect(
      files.filter(
        (path) =>
          path.endsWith('.spec.ts') ||
          path.startsWith('testing/') ||
          path === 'app/shared/icon/icon-ink-shifts.ts',
      ),
    ).toEqual([]);
  });

  it('is sorted and unique', () => {
    expect(names).toEqual([...new Set(names)].sort());
  });

  it('ignores names that only spec files use', () => {
    expect(names).not.toContain(SPEC_ONLY_GLYPH);
  });
});

describe('glyph-names.txt', () => {
  it('the glyph name list was extracted from the installed font', () => {
    const listed = glyphNamesFontVersion(FRONTEND);
    const installed = installedFontVersion(FRONTEND);
    if (listed !== installed) {
      throw new Error(
        `glyph-names.txt lists material-symbols ${listed || 'unknown'}, but ${installed} is installed. ` +
          'Run `npm run icons:glyph-names` from frontend/, then `npm run icons:measure`.',
      );
    }
  });
});

describe('installedFontVersion', () => {
  it('reads the material-symbols package version', () => {
    expect(installedFontVersion(FRONTEND)).toMatch(/^\d+\.\d+\.\d+$/);
  });
});
