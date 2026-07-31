import { SETTINGS_SECTIONS } from './settings-sections';

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
});
