import { SETTINGS_SECTIONS, sectionLabelKey } from './settings-sections';

describe('SETTINGS_SECTIONS', () => {
  it('has unique paths', () => {
    const paths = SETTINGS_SECTIONS.map((s) => s.path);
    expect(new Set(paths).size).toBe(paths.length);
  });

  it('keeps admin sections under the admin/ path prefix, and only them', () => {
    for (const s of SETTINGS_SECTIONS) {
      expect(s.path.startsWith('admin/')).toBe(s.group === 'admin');
    }
  });

  it('gives every section an icon and a label key', () => {
    for (const s of SETTINGS_SECTIONS) {
      expect(s.icon).not.toBe('');
      expect(s.labelKey).toMatch(/^\w+\./);
    }
  });

  it('reads a label back by path, so the nav and the document title cannot drift', () => {
    for (const s of SETTINGS_SECTIONS) {
      expect(sectionLabelKey(s.path)).toBe(s.labelKey);
    }
  });

  it('rejects a path no section owns rather than titling a page with nothing', () => {
    expect(() => sectionLabelKey('admin/nowhere')).toThrow();
  });
});
