import { join } from 'node:path';
import { TAG_ICONS } from '../app/shared/icon-choices';
import {
  iconSourceFiles,
  readGlyphNames,
  requireMatchingGlyphNames,
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
    const font = readGlyphNames(FRONTEND).names;
    expect(names.filter((name) => !font.has(name))).toEqual([]);
  });

  it('reads neither specs nor test helpers', () => {
    const files = iconSourceFiles(FRONTEND);
    expect(files).toContain('app/shared/icon-choices.ts');
    expect(
      files.filter((path) => path.endsWith('.spec.ts') || path.startsWith('testing/')),
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
    expect(() => requireMatchingGlyphNames(FRONTEND)).not.toThrow();
  });
});
