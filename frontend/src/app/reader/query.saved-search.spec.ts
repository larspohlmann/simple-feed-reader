import { convertToParamMap } from '@angular/router';
import {
  hasUnreadFilter,
  queryFromSelection,
  sameSelection,
  savedSearchParams,
  savedSearchTerm,
  selectionFromParams,
  selectionQueryParams,
} from './query';

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
    expect(params.unread).toBeNull();
  });

  it('savedSearchParams wraps a phrase search in quotes on q', () => {
    expect(savedSearchParams('climate change', false, true).q).toBe('"climate change"');
  });

  it.each([
    ['climate', false, false, 'climate'],
    ['rust lang', true, false, 'rust lang '],
    ['climate change', false, true, '"climate change"'],
  ])(
    'keeps the saved origin and matching mode for %s after decoding the link',
    (term, wholeWord, phrase, q) => {
      const { selection } = selectionFromParams(
        convertToParamMap(savedSearchParams(term, wholeWord, phrase)),
      );

      expect(selection).toEqual({
        kind: 'search',
        id: null,
        unread: false,
        term: q,
        searchOrigin: 'saved',
      });
      expect(queryFromSelection(selection)).toEqual({ view: 'all', q });
    },
  );

  it('distinguishes a saved search from direct search with the same term', () => {
    const saved = selectionFromParams(
      convertToParamMap({ q: 'climate', searchOrigin: 'saved' }),
    ).selection;
    const direct = selectionFromParams(convertToParamMap({ q: 'climate' })).selection;

    expect(sameSelection(saved, direct)).toBe(false);
  });

  it('keeps the unread refinement only for a saved-search result', () => {
    const saved = selectionFromParams(
      convertToParamMap({ q: 'climate', searchOrigin: 'saved', unread: '1' }),
    ).selection;
    const direct = selectionFromParams(convertToParamMap({ q: 'climate', unread: '1' })).selection;

    expect(saved).toEqual({
      kind: 'search',
      id: null,
      unread: true,
      term: 'climate',
      searchOrigin: 'saved',
    });
    expect(direct.unread).toBe(false);
  });

  it('offers and forwards the unread filter for a saved-search result', () => {
    const selection = {
      kind: 'search' as const,
      id: null,
      unread: true,
      term: 'climate',
      searchOrigin: 'saved' as const,
    };

    expect(hasUnreadFilter(selection)).toBe(true);
    expect(queryFromSelection(selection)).toEqual({
      view: 'all',
      q: 'climate',
      unread: true,
    });
  });

  it('does not offer the unread filter for a direct search', () => {
    expect(hasUnreadFilter({ kind: 'search', id: null, unread: false, term: 'climate' })).toBe(
      false,
    );
  });

  it('clears the saved origin when navigating to another list', () => {
    expect(selectionQueryParams({ view: 'saved-searches' })).toMatchObject({
      q: null,
      searchOrigin: null,
    });
  });
});
