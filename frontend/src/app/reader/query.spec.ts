import { convertToParamMap } from '@angular/router';
import {
  canScopedRefresh,
  isSearchableTerm,
  isTooShortToSearch,
  isWholeWordTerm,
  isPhraseTerm,
  listSelectionFrom,
  hasUnreadFilter,
  markReadTarget,
  normalizeSearchInput,
  queryFromSelection,
  sameSelection,
  searchWords,
  selectionFromParams,
  selectionQueryParams,
  visibleSearchTerm,
} from './query';

const pm = (o: Record<string, string>) => convertToParamMap(o);

describe('selectionFromParams', () => {
  it('defaults to all-items, showing everything', () => {
    const { selection, entryId } = selectionFromParams(pm({}));
    expect(selection).toEqual({ kind: 'all', id: null, unread: false });
    expect(entryId).toBeNull();
  });
  it('reads unread=1 as unread-only, and anything else as show-all', () => {
    expect(selectionFromParams(pm({ unread: '1' })).selection.unread).toBe(true);
    expect(selectionFromParams(pm({ unread: '0' })).selection.unread).toBe(false);
  });
  it('reads a subscription selection and open entry', () => {
    const { selection, entryId } = selectionFromParams(pm({ subscription: '7', entry: '42' }));
    expect(selection).toEqual({ kind: 'subscription', id: 7, unread: false });
    expect(entryId).toBe(42);
  });
  it('reads a tag selection', () => {
    expect(selectionFromParams(pm({ tag: '3' })).selection).toEqual({
      kind: 'tag',
      id: 3,
      unread: false,
    });
  });
  it('reads favorites/kept and ignores the unread toggle there', () => {
    expect(selectionFromParams(pm({ view: 'favorites', unread: '0' })).selection).toEqual({
      kind: 'favorites',
      id: null,
      unread: false,
    });
    expect(selectionFromParams(pm({ view: 'kept' })).selection.kind).toBe('kept');
  });
  it('reads for-you, and keeps its unread refinement (#710)', () => {
    expect(selectionFromParams(pm({ view: 'for-you', unread: '0' })).selection).toEqual({
      kind: 'for-you',
      id: null,
      unread: false,
    });
    expect(selectionFromParams(pm({ view: 'for-you', unread: '1' })).selection).toEqual({
      kind: 'for-you',
      id: null,
      unread: true,
    });
  });

  it('still ignores the unread toggle on the saved views, which already filter by state', () => {
    for (const view of ['favorites', 'kept', 'viewed'] as const) {
      expect(selectionFromParams(pm({ view, unread: '1' })).selection.unread).toBe(false);
    }
  });
  it('rejects non-positive/garbage ids', () => {
    expect(selectionFromParams(pm({ subscription: '0' })).selection.kind).toBe('all');
    expect(selectionFromParams(pm({ tag: 'x' })).selection.kind).toBe('all');
  });
  it('reads a search selection from a q of 3+ characters', () => {
    expect(selectionFromParams(pm({ q: 'angular' })).selection).toEqual({
      kind: 'search',
      id: null,
      unread: false,
      term: 'angular',
    });
  });
  it('lets q win over a tag present in the same URL', () => {
    expect(selectionFromParams(pm({ q: 'angular', tag: '3' })).selection).toEqual({
      kind: 'search',
      id: null,
      unread: false,
      term: 'angular',
    });
  });
  it('ignores a q shorter than the minimum and falls back to all', () => {
    expect(selectionFromParams(pm({ q: 'an' })).selection.kind).toBe('all');
  });
  it('ignores an empty or whitespace-only q', () => {
    expect(selectionFromParams(pm({ q: '' })).selection.kind).toBe('all');
    expect(selectionFromParams(pm({ q: '   ' })).selection.kind).toBe('all');
  });

  describe('trailing space as the whole-word signal (#408 follow-up)', () => {
    it('keeps a trailing space in the selection term', () => {
      expect(selectionFromParams(pm({ q: 'punk ' })).selection).toEqual({
        kind: 'search',
        id: null,
        unread: false,
        term: 'punk ',
      });
    });
    it('strips leading whitespace and collapses inner runs, but keeps the one trailing space', () => {
      expect(selectionFromParams(pm({ q: '  angular   js  ' })).selection).toEqual({
        kind: 'search',
        id: null,
        unread: false,
        term: 'angular js ',
      });
    });
    it('measures the minimum length on the trimmed value, so a trailing space does not buy an extra character', () => {
      // 'ab ' is 3 raw characters but 2 once the trailing space is set aside.
      expect(selectionFromParams(pm({ q: 'ab ' })).selection.kind).toBe('all');
    });
  });
});

describe('listSelectionFrom (#579)', () => {
  it('reads the list a search is layered over', () => {
    expect(listSelectionFrom({ tag: '5', q: 'angular' })).toEqual({
      kind: 'tag',
      id: 5,
      unread: false,
    });
  });

  it('keeps the unread refinement the search hid', () => {
    expect(listSelectionFrom({ tag: '5', unread: '1', q: 'angular' })).toEqual({
      kind: 'tag',
      id: 5,
      unread: true,
    });
  });

  it('reads a plain list unchanged', () => {
    expect(listSelectionFrom({ view: 'favorites' })).toEqual({
      kind: 'favorites',
      id: null,
      unread: false,
    });
  });

  it('does not touch the parameters it is given', () => {
    const params = { tag: '5', q: 'angular' };

    listSelectionFrom(params);

    expect(params).toEqual({ tag: '5', q: 'angular' });
  });
});

describe('selectionQueryParams', () => {
  it('pins the selection vocabulary — this must not shrink silently', () => {
    expect(Object.keys(selectionQueryParams({})).sort()).toEqual(
      ['entry', 'q', 'subscription', 'tag', 'view'].sort(),
    );
  });
  it('nulls every selection parameter the caller did not set', () => {
    expect(selectionQueryParams({})).toEqual({
      view: null,
      tag: null,
      subscription: null,
      entry: null,
      q: null,
    });
  });
  it('keeps only the params the caller set, nulling the rest', () => {
    expect(selectionQueryParams({ tag: 3 })).toEqual({
      view: null,
      tag: 3,
      subscription: null,
      entry: null,
      q: null,
    });
    expect(selectionQueryParams({ subscription: 7 })).toEqual({
      view: null,
      tag: null,
      subscription: 7,
      entry: null,
      q: null,
    });
    expect(selectionQueryParams({ view: 'favorites' })).toEqual({
      view: 'favorites',
      tag: null,
      subscription: null,
      entry: null,
      q: null,
    });
  });
  it('clears q along with everything else when nothing is set', () => {
    expect(selectionQueryParams({ q: null })).toEqual({
      view: null,
      tag: null,
      subscription: null,
      entry: null,
      q: null,
    });
  });
  // The results are cached so that a [queryParams] binding — one per sidebar
  // feed, per tag, per row's source pills — stops allocating a new object on
  // every change-detection pass and making RouterLink rebuild its href.
  describe('the shared result objects (#408 cleanup)', () => {
    it('hands the same reference back for the same argument', () => {
      expect(selectionQueryParams({ tag: 3 })).toBe(selectionQueryParams({ tag: 3 }));
      expect(selectionQueryParams({})).toBe(selectionQueryParams({}));
    });

    it('keeps different arguments apart', () => {
      expect(selectionQueryParams({ tag: 3 })).not.toBe(selectionQueryParams({ tag: 4 }));
      expect(selectionQueryParams({ tag: 3 })).not.toBe(selectionQueryParams({ subscription: 3 }));
      expect(selectionQueryParams({ view: 'favorites' })).not.toBe(
        selectionQueryParams({ view: 'kept' }),
      );
    });

    it('does not cache a search term, whose values are unbounded', () => {
      // One entry per term the user ever types would grow without limit, and
      // that call site is a single navigation rather than a template binding.
      expect(selectionQueryParams({ q: 'punk' })).not.toBe(selectionQueryParams({ q: 'punk' }));
      expect(selectionQueryParams({ q: 'punk' })).toEqual(selectionQueryParams({ q: 'punk' }));
    });

    it('still nulls the whole vocabulary on a cache hit', () => {
      selectionQueryParams({ tag: 9 });

      expect(selectionQueryParams({ tag: 9 })).toEqual({
        view: null,
        tag: 9,
        subscription: null,
        entry: null,
        q: null,
      });
    });
  });

  it('sets q and clears the rest for a search', () => {
    expect(selectionQueryParams({ q: 'angular' })).toEqual({
      view: null,
      tag: null,
      subscription: null,
      entry: null,
      q: 'angular',
    });
  });
});

describe('queryFromSelection', () => {
  it('maps all/tag/subscription through the unread toggle', () => {
    expect(queryFromSelection({ kind: 'all', id: null, unread: true })).toEqual({ view: 'unread' });
    expect(queryFromSelection({ kind: 'all', id: null, unread: false })).toEqual({ view: 'all' });
    expect(queryFromSelection({ kind: 'tag', id: 3, unread: true })).toEqual({
      view: 'unread',
      tag: 3,
    });
    expect(queryFromSelection({ kind: 'subscription', id: 7, unread: false })).toEqual({
      view: 'all',
      subscription: 7,
    });
  });
  it('maps curated views directly', () => {
    expect(queryFromSelection({ kind: 'favorites', id: null, unread: false })).toEqual({
      view: 'favorites',
    });
    expect(queryFromSelection({ kind: 'kept', id: null, unread: false })).toEqual({ view: 'kept' });
    expect(queryFromSelection({ kind: 'for-you', id: null, unread: false })).toEqual({
      view: 'for-you',
    });
  });
  // For you is the one list whose unread filter cannot be a view: the ranked
  // feed IS the view, so the filter rides beside it (#710).
  it('asks the ranked feed for unread picks with a flag, not a view', () => {
    expect(queryFromSelection({ kind: 'for-you', id: null, unread: true })).toEqual({
      view: 'for-you',
      unread: true,
    });
  });
  it('maps a search selection to view=all with q', () => {
    expect(
      queryFromSelection({ kind: 'search', id: null, unread: false, term: 'angular' }),
    ).toEqual({ view: 'all', q: 'angular' });
  });
  it('passes a trailing space through unchanged — the server reads it as whole-word match', () => {
    expect(queryFromSelection({ kind: 'search', id: null, unread: false, term: 'punk ' })).toEqual({
      view: 'all',
      q: 'punk ',
    });
  });
});

describe('markReadTarget', () => {
  it('maps selection to a mark-read scope (feed=subscription id)', () => {
    expect(markReadTarget({ kind: 'all', id: null, unread: true })).toEqual({ scope: 'all' });
    expect(markReadTarget({ kind: 'tag', id: 3, unread: true })).toEqual({ scope: 'tag', id: 3 });
    expect(markReadTarget({ kind: 'subscription', id: 7, unread: true })).toEqual({
      scope: 'feed',
      id: 7,
    });
    expect(markReadTarget({ kind: 'favorites', id: null, unread: false })).toBeNull();
  });

  // For you marks its picks read through its own endpoint — no scope, no id:
  // the list is the reader's own ranked feed (#710).
  it('maps the for-you selection to its own scope', () => {
    expect(markReadTarget({ kind: 'for-you', id: null, unread: false })).toEqual({
      scope: 'for-you',
    });
  });

  it('maps a search selection to a search scope, term verbatim', () => {
    expect(markReadTarget({ kind: 'search', id: null, unread: false, term: 'climate' })).toEqual({
      scope: 'search',
      term: 'climate',
    });
    // The trailing space is the whole-word-match signal and must reach the backend.
    expect(markReadTarget({ kind: 'search', id: null, unread: false, term: 'climate ' })).toEqual({
      scope: 'search',
      term: 'climate ',
    });
    expect(markReadTarget({ kind: 'search', id: null, unread: false, term: '' })).toBeNull();
  });
});

describe('hasUnreadFilter', () => {
  it('covers the browsable lists and the ranked feed', () => {
    expect(hasUnreadFilter({ kind: 'all', id: null, unread: true })).toBe(true);
    expect(hasUnreadFilter({ kind: 'tag', id: 3, unread: true })).toBe(true);
    expect(hasUnreadFilter({ kind: 'subscription', id: 7, unread: true })).toBe(true);
    expect(hasUnreadFilter({ kind: 'for-you', id: null, unread: true })).toBe(true);
  });
  it('leaves the saved views and a search alone — each is already a filter', () => {
    expect(hasUnreadFilter({ kind: 'favorites', id: null, unread: false })).toBe(false);
    expect(hasUnreadFilter({ kind: 'kept', id: null, unread: false })).toBe(false);
    expect(hasUnreadFilter({ kind: 'viewed', id: null, unread: false })).toBe(false);
    expect(hasUnreadFilter({ kind: 'search', id: null, unread: false, term: 'x' })).toBe(false);
  });
});

describe('canScopedRefresh', () => {
  it('is allowed for all/tag/subscription selections', () => {
    expect(canScopedRefresh({ kind: 'all', id: null, unread: true })).toBe(true);
    expect(canScopedRefresh({ kind: 'tag', id: 3, unread: true })).toBe(true);
    expect(canScopedRefresh({ kind: 'subscription', id: 7, unread: true })).toBe(true);
  });
  it('is disallowed for the cross-feed saved views', () => {
    expect(canScopedRefresh({ kind: 'favorites', id: null, unread: false })).toBe(false);
    expect(canScopedRefresh({ kind: 'kept', id: null, unread: false })).toBe(false);
    expect(canScopedRefresh({ kind: 'for-you', id: null, unread: false })).toBe(false);
  });
});

describe('sameSelection', () => {
  it('matches two selections that name the same list', () => {
    expect(
      sameSelection({ kind: 'tag', id: 3, unread: true }, { kind: 'tag', id: 3, unread: true }),
    ).toBe(true);
  });
  it('separates two tags', () => {
    expect(
      sameSelection({ kind: 'tag', id: 3, unread: true }, { kind: 'tag', id: 4, unread: true }),
    ).toBe(false);
  });
  it('separates the same id under a different kind', () => {
    expect(
      sameSelection(
        { kind: 'tag', id: 3, unread: true },
        { kind: 'subscription', id: 3, unread: true },
      ),
    ).toBe(false);
  });
  it('separates the unread view from the all view', () => {
    expect(
      sameSelection(
        { kind: 'all', id: null, unread: true },
        { kind: 'all', id: null, unread: false },
      ),
    ).toBe(false);
  });
  it('separates two search selections with different terms', () => {
    expect(
      sameSelection(
        { kind: 'search', id: null, unread: false, term: 'angular' },
        { kind: 'search', id: null, unread: false, term: 'react' },
      ),
    ).toBe(false);
  });
});

describe('visibleSearchTerm', () => {
  it('strips the trailing whole-word-mode space', () => {
    expect(visibleSearchTerm('daft ')).toBe('daft');
  });
  it('leaves a term without a trailing space unchanged', () => {
    expect(visibleSearchTerm('daft punk')).toBe('daft punk');
  });
  it('leaves inner spacing between terms untouched', () => {
    expect(visibleSearchTerm('daft punk ')).toBe('daft punk');
  });
  it('strips the wrapping quotes of a phrase, showing the bare inner text', () => {
    expect(visibleSearchTerm('"climate change"')).toBe('climate change');
  });
});

describe('isPhraseTerm (#702)', () => {
  it('is true for a query wrapped in double quotes', () => {
    expect(isPhraseTerm('"climate change"')).toBe(true);
  });
  it('is false for an unquoted query', () => {
    expect(isPhraseTerm('climate change')).toBe(false);
  });
  it('is false when the quotes wrap nothing usable', () => {
    expect(isPhraseTerm('""')).toBe(false);
    expect(isPhraseTerm('"   "')).toBe(false);
  });
  it('a phrase overrides the whole-word trailing space when both are present', () => {
    // The consumer decides precedence, but the two predicates must both fire so
    // it can: phrase wins, so a query carrying both signals reads as a phrase.
    expect(isPhraseTerm('"climate change" ')).toBe(true);
    expect(isWholeWordTerm('"climate change" ')).toBe(true);
  });
});

// These pin the two predicates at their edges rather than through a call site.
// The badge round taught the lesson: a consolidation that replaces four
// expressions with one helper is only safe if something states what the OLD
// expressions accepted — otherwise every test is written against the new
// helper and a narrowed rule passes them all.
describe('isSearchableTerm and isTooShortToSearch (#408 cleanup)', () => {
  it('accepts a term of exactly the minimum length', () => {
    expect(isSearchableTerm('ng2')).toBe(true);
    expect(isTooShortToSearch('ng2')).toBe(false);
  });

  it('rejects one character below the minimum', () => {
    expect(isSearchableTerm('ng')).toBe(false);
    expect(isTooShortToSearch('ng')).toBe(true);
  });

  it('measures the floor on the trimmed value, so a trailing space does not count', () => {
    // 'ab ' is three raw characters; the trailing space is the whole-word
    // signal, not a character of the word.
    expect(isSearchableTerm('ab ')).toBe(false);
    expect(isTooShortToSearch('ab ')).toBe(true);
  });

  it('counts the word when the trailing space rides along on a long-enough term', () => {
    expect(isSearchableTerm('punk ')).toBe(true);
    expect(isTooShortToSearch('punk ')).toBe(false);
  });

  it('treats the empty term as no search rather than as too short', () => {
    // The distinction matters: the field emits '' to END a search, so an
    // empty box must fall through the too-short guard rather than be swallowed.
    expect(isSearchableTerm('')).toBe(false);
    expect(isTooShortToSearch('')).toBe(false);
    expect(isTooShortToSearch('   ')).toBe(false);
  });
});

describe('searchWords (#408 cleanup)', () => {
  it('splits a multi-word term', () => {
    expect(searchWords('daft punk')).toEqual(['daft', 'punk']);
  });

  it('drops the empty piece a trailing space would leave', () => {
    expect(searchWords('punk ')).toEqual(['punk']);
  });

  it('returns a phrase as one contiguous run, not word by word (#702)', () => {
    expect(searchWords('"climate change"')).toEqual(['climate change']);
  });

  // Written as an escape, not the literal character: a no-break space is
  // indistinguishable from a plain space in the source, and the whole point
  // of the case is that the two are treated alike.
  it('splits on the same whitespace class as the rest of the vocabulary', () => {
    expect(searchWords('daft\u00a0punk')).toEqual(['daft', 'punk']);
    expect(searchWords('daft\tpunk')).toEqual(['daft', 'punk']);
    expect(searchWords('daft\npunk')).toEqual(['daft', 'punk']);
  });

  it('is empty for a term with no words', () => {
    expect(searchWords('')).toEqual([]);
    expect(searchWords('   ')).toEqual([]);
  });
});

describe('isWholeWordTerm (#408 follow-up)', () => {
  it('is true for a term ending in a plain ASCII space', () => {
    expect(isWholeWordTerm('punk ')).toBe(true);
  });
  it('is true for a term ending in a non-breaking space', () => {
    expect(isWholeWordTerm(`punk\u00A0`)).toBe(true);
  });
  it('is false for a term with no trailing whitespace', () => {
    expect(isWholeWordTerm('punk')).toBe(false);
  });
  it('is false for an empty term', () => {
    expect(isWholeWordTerm('')).toBe(false);
  });
  // The backend's class is [\s\p{Z}] (SearchTerms::WHITESPACE) because PHP's
  // \s is ASCII-only under /u; \p{Z} alone drops these ASCII control
  // whitespace characters, which is exactly what round 1 got wrong.
  it('is true for a term ending in a tab', () => {
    expect(isWholeWordTerm('punk\t')).toBe(true);
  });
  it('is true for a term ending in a newline', () => {
    expect(isWholeWordTerm('punk\n')).toBe(true);
  });
  it('is true for a term ending in a carriage return', () => {
    expect(isWholeWordTerm('punk\r')).toBe(true);
  });
});

describe('normalizeSearchInput and a trailing NBSP (#408 follow-up)', () => {
  // A pasted or autocorrected string can end in a no-break space (U+00A0)
  // rather than a plain one. JS's `\s` already treats it as whitespace, so
  // it must drive the same whole-word signal a plain trailing space does —
  // agreeing with the backend's `SearchTerms::WHITESPACE`, which now also
  // matches it via `\p{Z}`.
  it('reads a trailing NBSP as the whole-word signal, normalized to a plain space', () => {
    expect(normalizeSearchInput('daft punk ')).toBe('daft punk ');
  });
  it('collapses an inner NBSP the same as a plain space', () => {
    expect(normalizeSearchInput('daft punk')).toBe('daft punk');
  });
});

describe('the combined saved-searches view', () => {
  it('reads view=saved-searches as its own selection', () => {
    const { selection } = selectionFromParams(convertToParamMap({ view: 'saved-searches' }));

    expect(selection).toEqual({ kind: 'saved-searches', id: null, unread: false });
  });

  it('takes the unread refinement like every browsable list', () => {
    const { selection } = selectionFromParams(
      convertToParamMap({ view: 'saved-searches', unread: '1' }),
    );

    expect(selection.unread).toBe(true);
  });

  it('offers the unread switch', () => {
    expect(hasUnreadFilter({ kind: 'saved-searches', id: null, unread: false })).toBe(true);
  });

  it('asks for its own list, with the unread filter beside the view', () => {
    expect(queryFromSelection({ kind: 'saved-searches', id: null, unread: false })).toEqual({
      view: 'saved-searches',
    });
    expect(queryFromSelection({ kind: 'saved-searches', id: null, unread: true })).toEqual({
      view: 'saved-searches',
      unread: true,
    });
  });

  it('marks all read through its own scope', () => {
    expect(markReadTarget({ kind: 'saved-searches', id: null, unread: false })).toEqual({
      scope: 'saved-searches',
    });
  });

  it('is not a scoped-refresh list', () => {
    expect(canScopedRefresh({ kind: 'saved-searches', id: null, unread: false })).toBe(false);
  });

  it('loses to a searchable q in the URL, like every other list', () => {
    const { selection } = selectionFromParams(
      convertToParamMap({ view: 'saved-searches', q: 'climate' }),
    );

    expect(selection.kind).toBe('search');
  });
});
