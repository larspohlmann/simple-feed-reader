import { SETTINGS_SECTIONS, SettingsSectionPath, sectionLabelKey } from './settings-sections';

describe('SETTINGS_SECTIONS', () => {
  it('has unique paths', () => {
    const paths = SETTINGS_SECTIONS.map((section) => section.path);
    expect(new Set(paths).size).toBe(paths.length);
  });

  it('keeps admin sections under the admin/ path prefix, and only them', () => {
    for (const section of SETTINGS_SECTIONS) {
      expect(section.path.startsWith('admin/')).toBe(section.group === 'admin');
    }
  });

  it('gives every section an icon and a label key', () => {
    for (const section of SETTINGS_SECTIONS) {
      expect(section.icon).not.toBe('');
      expect(section.labelKey).toMatch(/^\w+\./);
    }
  });

  it('lists no Profile section; the profile lives on the AI page (#1353)', () => {
    expect(SETTINGS_SECTIONS.map((section) => section.path)).not.toContain('profile');
  });

  it('rejects a path no section owns rather than titling a page with nothing', () => {
    // The parameter type keeps a caller from asking in the first place; the
    // cast is how a spec reaches the guard behind it.
    expect(() => sectionLabelKey('admin/nowhere' as SettingsSectionPath)).toThrow();
  });
});
