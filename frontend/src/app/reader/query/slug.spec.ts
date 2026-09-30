import { entryParam, entryIdFromParam } from './slug';

describe('entry deep-link slugs', () => {
  it('builds an id-prefixed slug from the title (diacritics folded)', () => {
    expect(entryParam(514, 'Wadephul: "Afrika sollte für uns Chancenkontinent sein"')).toBe(
      '514-wadephul-afrika-sollte-fur-uns-chancenkontinent-sein',
    );
  });

  it('falls back to the bare id when the title has no slug characters', () => {
    expect(entryParam(7, '!!! ??? ')).toBe('7');
  });

  it('caps the slug length', () => {
    expect(entryParam(1, 'a'.repeat(200)).length).toBeLessThanOrEqual('1-'.length + 80);
  });

  it('parses the id from a bare id and from an id-slug', () => {
    expect(entryIdFromParam('514')).toBe(514);
    expect(entryIdFromParam('514-wadephul-afrika')).toBe(514);
  });

  it('rejects garbage, zero, and non-leading-digit values', () => {
    expect(entryIdFromParam(null)).toBeNull();
    expect(entryIdFromParam('abc')).toBeNull();
    expect(entryIdFromParam('0-foo')).toBeNull();
    expect(entryIdFromParam('-5')).toBeNull();
    expect(entryIdFromParam('12abc')).toBeNull();
  });

  it('round-trips: the id parsed from a built param equals the source id', () => {
    expect(entryIdFromParam(entryParam(42, 'Some Long Title Here'))).toBe(42);
  });
});
