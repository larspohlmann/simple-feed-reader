import { asLang, detectLang } from './language';

describe('detectLang', () => {
  it.each([
    ['fr', 'fr'],
    ['fr-CA', 'fr'],
    ['FR-be', 'fr'],
    ['es', 'es'],
    ['es-MX', 'es'],
    ['es-419', 'es'],
    ['de-AT', 'de'],
    ['DE-ch', 'de'],
  ])('maps the browser tag %s to %s', (tag, lang) => {
    expect(detectLang(tag)).toBe(lang);
  });

  it('falls back to English for an untranslated or missing tag', () => {
    for (const tag of ['en', 'en-GB', 'nl-NL', 'fy', 'eo', 'et', 'des', '', null, undefined]) {
      expect(detectLang(tag)).toBe('en');
    }
  });
});

describe('asLang', () => {
  it('accepts supported languages and rejects the rest', () => {
    expect(asLang('en')).toBe('en');
    expect(asLang('de')).toBe('de');
    expect(asLang('fr')).toBe('fr');
    expect(asLang('es')).toBe('es');
    expect(asLang('it')).toBeNull();
    expect(asLang('')).toBeNull();
    expect(asLang(null)).toBeNull();
  });
});
