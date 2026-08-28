import { savedSearchParams, savedSearchTerm } from './query';

describe('saved-search query helpers', () => {
  it('savedSearchTerm appends the whole-word trailing space only when whole-word', () => {
    expect(savedSearchTerm('climate', false, false)).toBe('climate');
    expect(savedSearchTerm('rust lang', true, false)).toBe('rust lang ');
  });

  it('savedSearchTerm wraps a phrase in double quotes', () => {
    expect(savedSearchTerm('climate change', false, true)).toBe('"climate change"');
  });

  it('savedSearchParams puts the reconstructed term on q and clears the rest', () => {
    const params = savedSearchParams('rust lang', true, false);
    expect(params.q).toBe('rust lang ');
    expect(params.view).toBeNull();
    expect(params.tag).toBeNull();
    expect(params.subscription).toBeNull();
    expect(params.entry).toBeNull();
  });

  it('savedSearchParams wraps a phrase search in quotes on q', () => {
    expect(savedSearchParams('climate change', false, true).q).toBe('"climate change"');
  });
});
