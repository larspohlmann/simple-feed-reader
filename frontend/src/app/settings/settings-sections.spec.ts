import { SETTINGS_SECTIONS, SettingsSectionPath, sectionLabelKey } from './settings-sections';

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

  it('rejects a path no section owns rather than titling a page with nothing', () => {
    // The parameter type keeps a caller from asking in the first place; the
    // cast is how a spec reaches the guard behind it.
    expect(() => sectionLabelKey('admin/nowhere' as SettingsSectionPath)).toThrow();
  });
});
