import { asLang, detectLang } from './language';

describe('detectLang', () => {
  it('picks German for any de* browser tag', () => {
    for (const tag of ['de', 'de-DE', 'de-AT', 'DE-ch']) {
      expect(detectLang(tag)).toBe('de');
    }
  });

  it('falls back to English for anything else or missing', () => {
    for (const tag of ['en', 'en-GB', 'fr', 'nl-NL', '', null, undefined]) {
      expect(detectLang(tag)).toBe('en');
    }
  });
});

describe('asLang', () => {
  it('accepts supported languages and rejects the rest', () => {
    expect(asLang('en')).toBe('en');
    expect(asLang('de')).toBe('de');
    expect(asLang('fr')).toBeNull();
    expect(asLang('')).toBeNull();
    expect(asLang(null)).toBeNull();
  });
});
