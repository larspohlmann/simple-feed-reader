import { savedSearchParams, savedSearchTerm } from './query';

describe('saved-search query helpers', () => {
  it('savedSearchTerm appends the whole-word trailing space only when whole-word', () => {
    expect(savedSearchTerm('climate', false)).toBe('climate');
    expect(savedSearchTerm('rust lang', true)).toBe('rust lang ');
  });

  it('savedSearchParams puts the reconstructed term on q and clears the rest', () => {
    const params = savedSearchParams('rust lang', true);
    expect(params.q).toBe('rust lang ');
    expect(params.view).toBeNull();
    expect(params.tag).toBeNull();
    expect(params.subscription).toBeNull();
    expect(params.entry).toBeNull();
  });
});
