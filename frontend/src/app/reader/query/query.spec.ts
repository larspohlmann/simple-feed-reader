import { convertToParamMap } from '@angular/router';
import {
  canScopedRefresh,
  hasListOrder,
  listOrderKey,
  listOrderOf,
  listSelectionFrom,
  hasUnreadFilter,
  identityKey,
  isUnreadOnly,
  isSingleStreamView,
  offersMarkAboveRead,
  sameSelection,
  selectionFromParams,
  searchTermOf,
  selectedGroupOf,
  selectedSavedSearchIdOf,
  selectedTagIdOf,
  selectionQueryParams,
  trendingWindowOf,
  visibleSearchTerm,
  withListOrder,
  withUnreadPreference,
  type Selection,
  type ViewContext,
} from './query';
import { queryFromSelection } from './entry-query';
import { markReadTarget } from './mark-read-target';
import {
  isPhraseTerm,
  isSearchableTerm,
  isTooShortToSearch,
  isWholeWordTerm,
  normalizeSearchInput,
  searchWords,
} from './search-term';
import { selectionFromRoute } from './reader-matcher';

const pm = (params: Record<string, string>) => convertToParamMap(params);

/** An account that offers Trending and stored no window. */
const ON: ViewContext = { trending: true, trendingWindow: 'day' };

describe('selectionFromParams', () => {
  it('defaults to all-items, showing everything', () => {
    const { selection, entryId } = selectionFromParams(pm({}), ON);
    expect(selection).toEqual({ kind: 'all', unread: false });
    expect(entryId).toBeNull();
  });
  it('ignores an unread parameter — the filter lives in localStorage (#1126)', () => {
    expect(isUnreadOnly(selectionFromParams(pm({ unread: '1' }), ON).selection)).toBe(false);
    expect(
      isUnreadOnly(selectionFromParams(pm({ view: 'for-you', unread: '1' }), ON).selection),
    ).toBe(false);
  });
  it('reads a subscription selection and open entry', () => {
    const { selection, entryId } = selectionFromParams(pm({ subscription: '7', entry: '42' }), ON);
    expect(selection).toEqual({ kind: 'subscription', id: 7, unread: false });
    expect(entryId).toBe(42);
  });
  it("reads a network's combined view", () => {
    expect(selectionFromParams(pm({ network: 'mastodon' }), ON).selection).toEqual({
      kind: 'network',
      unread: false,
      network: 'mastodon',
    });
  });
  it("reads a feed group's combined view", () => {
    expect(selectionFromParams(pm({ group: 'tags' }), ON).selection).toEqual({
      kind: 'group',
      unread: false,
      group: 'tags',
    });
  });
  it('ignores a feed group it does not know', () => {
    expect(selectionFromParams(pm({ group: 'folders' }), ON).selection.kind).toBe('all');
  });
  it("maps a feed group's view through the unread toggle", () => {
    expect(queryFromSelection({ kind: 'group', unread: true, group: 'feeds' })).toEqual({
      view: 'unread',
      group: 'feeds',
    });
  });
  it('marks a feed group read by its name', () => {
    expect(markReadTarget({ kind: 'group', unread: false, group: 'tags' })).toEqual({
      scope: 'group',
      group: 'tags',
    });
  });
  it('tells the two feed groups apart', () => {
    const tags: Selection = { kind: 'group', unread: false, group: 'tags' };
    expect(sameSelection(tags, { ...tags, group: 'feeds' })).toBe(false);
    expect(listOrderKey(tags)).toBe('group:tags');
    expect(canScopedRefresh(tags)).toBe(true);
  });
  it('ignores a network it does not know', () => {
    expect(selectionFromParams(pm({ network: 'myspace' }), ON).selection.kind).toBe('all');
  });
  it('reads a tag selection', () => {
    expect(selectionFromParams(pm({ tag: '3' }), ON).selection).toEqual({
      kind: 'tag',
      id: 3,
      unread: false,
    });
  });
  it('reads favorites/kept', () => {
    expect(selectionFromParams(pm({ view: 'favorites' }), ON).selection).toEqual({
      kind: 'favorites',
    });
    expect(selectionFromParams(pm({ view: 'kept' }), ON).selection.kind).toBe('kept');
  });
  it('reads view=shared into the shared selection', () => {
    expect(selectionFromParams(pm({ view: 'shared' }), ON).selection).toEqual({
      kind: 'shared',
    });
  });
  it('reads for-you', () => {
    expect(selectionFromParams(pm({ view: 'for-you' }), ON).selection).toEqual({
      kind: 'for-you',
      unread: false,
    });
  });

  it('rejects non-positive/garbage ids', () => {
    expect(selectionFromParams(pm({ subscription: '0' }), ON).selection.kind).toBe('all');
    expect(selectionFromParams(pm({ tag: 'x' }), ON).selection.kind).toBe('all');
  });
  it('reads a search selection from a q of 3+ characters', () => {
    expect(selectionFromParams(pm({ q: 'angular' }), ON).selection).toEqual({
      kind: 'search',
      unread: false,
      term: 'angular',
    });
  });
  it('lets q win over a tag present in the same URL', () => {
    expect(selectionFromParams(pm({ q: 'angular', tag: '3' }), ON).selection).toEqual({
      kind: 'search',
      unread: false,
      term: 'angular',
    });
  });
  it('ignores a q shorter than the minimum and falls back to all', () => {
    expect(selectionFromParams(pm({ q: 'an' }), ON).selection.kind).toBe('all');
  });
  it('ignores an empty or whitespace-only q', () => {
    expect(selectionFromParams(pm({ q: '' }), ON).selection.kind).toBe('all');
    expect(selectionFromParams(pm({ q: '   ' }), ON).selection.kind).toBe('all');
  });

  describe('trailing space as the whole-word signal (#408 follow-up)', () => {
    it('keeps a trailing space in the selection term', () => {
      expect(selectionFromParams(pm({ q: 'punk ' }), ON).selection).toEqual({
        kind: 'search',
        unread: false,
        term: 'punk ',
      });
    });
    it('strips leading whitespace and collapses inner runs, but keeps the one trailing space', () => {
      expect(selectionFromParams(pm({ q: '  angular   js  ' }), ON).selection).toEqual({
        kind: 'search',
        unread: false,
        term: 'angular js ',
      });
    });
    it('measures the minimum length on the trimmed value, so a trailing space does not buy an extra character', () => {
      // 'ab ' is 3 raw characters but 2 once the trailing space is set aside.
      expect(selectionFromParams(pm({ q: 'ab ' }), ON).selection.kind).toBe('all');
    });
  });
});

describe('listSelectionFrom (#579)', () => {
  it('reads the list a search is layered over', () => {
    expect(listSelectionFrom({ tag: '5', q: 'angular' }, ON)).toEqual({
      kind: 'tag',
      id: 5,
      unread: false,
    });
  });

  it('reads a plain list unchanged', () => {
    expect(listSelectionFrom({ view: 'favorites' }, ON)).toEqual({
      kind: 'favorites',
    });
  });

  it('does not touch the parameters it is given', () => {
    const params = { tag: '5', q: 'angular' };

    listSelectionFrom(params, ON);

    expect(params).toEqual({ tag: '5', q: 'angular' });
  });
});

describe('selectionQueryParams', () => {
  it('pins the selection vocabulary — this must not shrink silently', () => {
    expect(Object.keys(selectionQueryParams({})).sort()).toEqual(
      [
        'entry',
        'feedKind',
        'group',
        'link',
        'network',
        'q',
        'subscription',
        'tag',
        'view',
        'window',
      ].sort(),
    );
  });
  it('nulls every selection parameter the caller did not set', () => {
    expect(selectionQueryParams({})).toEqual({
      view: null,
      tag: null,
      network: null,
      feedKind: null,
      group: null,
      subscription: null,
      entry: null,
      link: null,
      q: null,
      window: null,
    });
  });
  it('keeps only the params the caller set, nulling the rest', () => {
    expect(selectionQueryParams({ tag: 3 })).toEqual({
      view: null,
      tag: 3,
      network: null,
      feedKind: null,
      group: null,
      subscription: null,
      entry: null,
      link: null,
      q: null,
      window: null,
    });
    expect(selectionQueryParams({ subscription: 7 })).toEqual({
      view: null,
      tag: null,
      network: null,
      feedKind: null,
      group: null,
      subscription: 7,
      entry: null,
      link: null,
      q: null,
      window: null,
    });
    expect(selectionQueryParams({ view: 'favorites' })).toEqual({
      view: 'favorites',
      tag: null,
      network: null,
      feedKind: null,
      group: null,
      subscription: null,
      entry: null,
      link: null,
      q: null,
      window: null,
    });
  });
  it('clears q along with everything else when nothing is set', () => {
    expect(selectionQueryParams({ q: null })).toEqual({
      view: null,
      tag: null,
      network: null,
      feedKind: null,
      group: null,
      subscription: null,
      entry: null,
      link: null,
      q: null,
      window: null,
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
        network: null,
        feedKind: null,
        group: null,
        subscription: null,
        entry: null,
        link: null,
        q: null,
        window: null,
      });
    });
  });

  it('sets q and clears the rest for a search', () => {
    expect(selectionQueryParams({ q: 'angular' })).toEqual({
      view: null,
      tag: null,
      network: null,
      feedKind: null,
      group: null,
      subscription: null,
      entry: null,
      link: null,
      q: 'angular',
      window: null,
    });
  });
});

describe('hasUnreadFilter', () => {
  it('covers the browsable lists and the ranked feed', () => {
    expect(hasUnreadFilter({ kind: 'all', unread: true })).toBe(true);
    expect(hasUnreadFilter({ kind: 'tag', id: 3, unread: true })).toBe(true);
    expect(hasUnreadFilter({ kind: 'subscription', id: 7, unread: true })).toBe(true);
    expect(hasUnreadFilter({ kind: 'for-you', unread: true })).toBe(true);
    expect(hasUnreadFilter({ kind: 'network', unread: true, network: 'bluesky' })).toBe(true);
    expect(hasUnreadFilter({ kind: 'saved-search', id: 42, unread: false })).toBe(true);
    expect(hasUnreadFilter({ kind: 'search', unread: false, term: 'x' })).toBe(true);
  });
  it('leaves the saved views alone — each is already a filter', () => {
    expect(hasUnreadFilter({ kind: 'favorites' })).toBe(false);
    expect(hasUnreadFilter({ kind: 'kept' })).toBe(false);
    expect(hasUnreadFilter({ kind: 'viewed' })).toBe(false);
  });
});

describe('withUnreadPreference (#1126)', () => {
  it('refines every list that offers the switch', () => {
    const lists: Selection[] = [
      { kind: 'all', unread: false },
      { kind: 'tag', id: 3, unread: false },
      { kind: 'subscription', id: 7, unread: false },
      { kind: 'for-you', unread: false },
      { kind: 'saved-searches', unread: false },
      { kind: 'saved-search', id: 42, unread: false },
      { kind: 'search', unread: false, term: 'angular' },
    ];
    for (const list of lists) {
      expect(withUnreadPreference(list, true)).toEqual({ ...list, unread: true });
      expect(withUnreadPreference(list, false)).toEqual({ ...list, unread: false });
    }
  });

  it('leaves the state views showing everything', () => {
    for (const kind of ['favorites', 'kept', 'viewed'] as const) {
      const list: Selection = { kind };
      expect(withUnreadPreference(list, true)).toEqual(list);
    }
  });
});

describe('canScopedRefresh', () => {
  it('is allowed for all/tag/subscription selections', () => {
    expect(canScopedRefresh({ kind: 'all', unread: true })).toBe(true);
    expect(canScopedRefresh({ kind: 'tag', id: 3, unread: true })).toBe(true);
    expect(canScopedRefresh({ kind: 'subscription', id: 7, unread: true })).toBe(true);
    expect(canScopedRefresh({ kind: 'network', unread: true, network: 'mastodon' })).toBe(true);
  });
  it('is disallowed for the cross-feed saved views', () => {
    expect(canScopedRefresh({ kind: 'favorites' })).toBe(false);
    expect(canScopedRefresh({ kind: 'kept' })).toBe(false);
    expect(canScopedRefresh({ kind: 'for-you', unread: false })).toBe(false);
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
    expect(sameSelection({ kind: 'all', unread: true }, { kind: 'all', unread: false })).toBe(
      false,
    );
  });
  it('separates two search selections with different terms', () => {
    expect(
      sameSelection(
        { kind: 'search', unread: false, term: 'angular' },
        { kind: 'search', unread: false, term: 'react' },
      ),
    ).toBe(false);
  });
});

describe('list order', () => {
  const tag: Selection = { kind: 'tag', id: 3, unread: false };

  it('reads an unordered selection as newest first', () => {
    expect(listOrderOf(tag)).toBe('newest');
    expect(listOrderOf(withListOrder(tag, 'oldest'))).toBe('oldest');
  });

  it('never orders the ranked for-you feed', () => {
    const forYou: Selection = { kind: 'for-you', unread: false };
    expect(hasListOrder(forYou)).toBe(false);
    expect(withListOrder(forYou, 'oldest')).toBe(forYou);
    expect(listOrderKey(forYou)).toBeNull();
  });

  it('keys each feed, tag, saved search and view apart, and every search term together', () => {
    const key = (selection: Selection) => listOrderKey(selection);
    expect(key({ kind: 'all', unread: true })).toBe('all');
    expect(key({ kind: 'viewed' })).toBe('viewed');
    expect(key({ kind: 'saved-searches', unread: false })).toBe('saved-searches');
    expect(key(tag)).toBe('tag:3');
    expect(key({ kind: 'subscription', id: 12, unread: false })).toBe('subscription:12');
    expect(key({ kind: 'saved-search', id: 7, unread: false })).toBe('saved-search:7');
    expect(key({ kind: 'network', unread: false, network: 'bluesky' })).toBe('network:bluesky');
    expect(key({ kind: 'search', unread: false, term: 'angular' })).toBe('search');
    expect(key({ kind: 'search', unread: false, term: 'react' })).toBe('search');
  });

  it('tells two networks apart', () => {
    expect(
      sameSelection(
        { kind: 'network', unread: false, network: 'bluesky' },
        { kind: 'network', unread: false, network: 'mastodon' },
      ),
    ).toBe(false);
  });

  it('tells a list and its reversed self apart', () => {
    expect(sameSelection(tag, withListOrder(tag, 'oldest'))).toBe(false);
    expect(sameSelection(tag, withListOrder(tag, 'newest'))).toBe(true);
  });

  it('asks for oldest first only when the selection is ordered so', () => {
    expect(queryFromSelection(withListOrder(tag, 'oldest'))).toEqual({
      view: 'all',
      tag: 3,
      order: 'oldest',
    });
    expect(queryFromSelection(tag)).toEqual({ view: 'all', tag: 3 });
    expect(
      queryFromSelection(withListOrder({ kind: 'search', unread: true, term: 'x y' }, 'oldest')),
    ).toEqual({ view: 'all', q: 'x y', unread: true, order: 'oldest' });
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

// These pin the two predicates at their edges rather than through a call
// site. A consolidation that replaces four expressions with one helper is
// only safe if something states what the OLD expressions accepted.
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
  // The backend's class is [\s\p{Z}] (SearchTermsModel::WHITESPACE) because PHP's
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
  // A pasted or autocorrected string can end in a no-break space (U+00A0). JS's
  // `\s` already treats it as whitespace, so it must drive the same whole-word
  // signal a plain space does — matching the backend's `\p{Z}` class.
  it('reads a trailing NBSP as the whole-word signal, normalized to a plain space', () => {
    expect(normalizeSearchInput('daft punk ')).toBe('daft punk ');
  });
  it('collapses an inner NBSP the same as a plain space', () => {
    expect(normalizeSearchInput('daft punk')).toBe('daft punk');
  });
});

describe('the combined saved-searches view', () => {
  it('reads view=saved-searches as its own selection', () => {
    const { selection } = selectionFromParams(convertToParamMap({ view: 'saved-searches' }), ON);

    expect(selection).toEqual({ kind: 'saved-searches', unread: false });
  });

  it('ignores an unread parameter (#1126)', () => {
    const { selection } = selectionFromParams(
      convertToParamMap({ view: 'saved-searches', unread: '1' }),
      ON,
    );

    expect(isUnreadOnly(selection)).toBe(false);
  });

  it('offers the unread switch', () => {
    expect(hasUnreadFilter({ kind: 'saved-searches', unread: false })).toBe(true);
  });

  it('asks for its own list, with the unread filter beside the view', () => {
    expect(queryFromSelection({ kind: 'saved-searches', unread: false })).toEqual({
      view: 'saved-searches',
    });
    expect(queryFromSelection({ kind: 'saved-searches', unread: true })).toEqual({
      view: 'saved-searches',
      unread: true,
    });
  });

  it('marks all read through its own scope', () => {
    expect(markReadTarget({ kind: 'saved-searches', unread: false })).toEqual({
      scope: 'saved-searches',
    });
  });

  it('is not a scoped-refresh list', () => {
    expect(canScopedRefresh({ kind: 'saved-searches', unread: false })).toBe(false);
  });

  it('loses to a searchable q in the URL, like every other list', () => {
    const { selection } = selectionFromParams(
      convertToParamMap({ view: 'saved-searches', q: 'climate' }),
      ON,
    );

    expect(selection.kind).toBe('search');
  });
});

describe('selectionFromRoute', () => {
  const noQuery = convertToParamMap({});

  it('maps the "all" path segment to the combined saved-searches selection', () => {
    const { selection } = selectionFromRoute(
      convertToParamMap({ savedSearch: 'all' }),
      noQuery,
      ON,
    );
    expect(selection).toEqual({ kind: 'saved-searches', unread: false });
  });

  it('maps a slug to a single saved-search selection by its leading id', () => {
    const { selection } = selectionFromRoute(
      convertToParamMap({ savedSearch: '42-climate' }),
      noQuery,
      ON,
    );
    expect(selection).toEqual({ kind: 'saved-search', id: 42, unread: false });
  });

  it('falls back to the combined view for a slug with no leading id', () => {
    const { selection } = selectionFromRoute(
      convertToParamMap({ savedSearch: 'climate' }),
      noQuery,
      ON,
    );
    expect(selection).toEqual({ kind: 'saved-searches', unread: false });
  });

  it('falls back to the combined view for a slug whose leading digits are not anchored (#1118)', () => {
    // "42x" is not a valid leading id: entryIdFromParam's anchored regex
    // requires the digits to end the slug or be followed by a hyphen.
    const { selection } = selectionFromRoute(
      convertToParamMap({ savedSearch: '42x' }),
      noQuery,
      ON,
    );
    expect(selection).toEqual({ kind: 'saved-searches', unread: false });
  });

  it('ignores an unread parameter on a path selection (#1126)', () => {
    const { selection } = selectionFromRoute(
      convertToParamMap({ savedSearch: '42-climate' }),
      convertToParamMap({ unread: '1' }),
      ON,
    );
    expect(isUnreadOnly(selection)).toBe(false);
  });

  it('falls back to query-param selection when no saved-search segment is present', () => {
    const { selection } = selectionFromRoute(noQuery, convertToParamMap({ tag: '7' }), ON);
    expect(selection).toEqual({ kind: 'tag', id: 7, unread: false });
  });

  it('carries the entry overlay onto a single saved-search path', () => {
    const { entryId } = selectionFromRoute(
      convertToParamMap({ savedSearch: '42-climate' }),
      convertToParamMap({ entry: '99' }),
      ON,
    );
    expect(entryId).toBe(99);
  });

  it('carries the entry overlay onto the combined saved-search path', () => {
    const { entryId } = selectionFromRoute(
      convertToParamMap({ savedSearch: 'all' }),
      convertToParamMap({ entry: '99' }),
      ON,
    );
    expect(entryId).toBe(99);
  });
});

describe("a network's profile feeds", () => {
  const params = (values: Record<string, string>) => convertToParamMap(values);
  const profiles: Selection = {
    kind: 'network',
    unread: false,
    network: 'mastodon',
    feedKind: 'profile',
  };

  it('reads the feed kind beside the network', () => {
    expect(
      selectionFromParams(params({ network: 'mastodon', feedKind: 'profile' }), ON).selection,
    ).toEqual(profiles);
  });

  it('reads a search feed kind beside the network', () => {
    expect(
      selectionFromParams(params({ network: 'bluesky', feedKind: 'search' }), ON).selection,
    ).toEqual({ ...profiles, network: 'bluesky', feedKind: 'search' });
  });

  it('ignores an unknown feed kind', () => {
    expect(
      selectionFromParams(params({ network: 'mastodon', feedKind: 'home' }), ON).selection,
    ).toEqual({ kind: 'network', unread: false, network: 'mastodon' });
  });

  it('is a different list from the whole network', () => {
    expect(sameSelection(profiles, { ...profiles, feedKind: undefined })).toBe(false);
    expect(listOrderKey(profiles)).toBe('network:mastodon:profile');
  });

  it('queries, marks read and links with the feed kind', () => {
    expect(queryFromSelection(profiles)).toEqual({
      view: 'all',
      network: 'mastodon',
      feedKind: 'profile',
    });
    expect(markReadTarget(profiles)).toEqual({
      scope: 'network',
      network: 'mastodon',
      feedKind: 'profile',
    });
    expect(selectionQueryParams({ network: 'mastodon' }).feedKind).toBeNull();
  });
});

describe('the Trending view (#2017)', () => {
  const trending = { kind: 'trending', window: 'day' } as const;
  const storedWeek: ViewContext = { trending: true, trendingWindow: 'week' };

  it('reads the window the URL names, and asks for no change (D40)', () => {
    expect(selectionFromParams(pm({ view: 'trending', window: 'week' }), ON)).toEqual({
      selection: { ...trending, window: 'week' },
      entryId: null,
      urlCorrection: null,
    });
  });

  it("opens the account's stored window for a bare link, and has the URL name it", () => {
    expect(selectionFromParams(pm({ view: 'trending' }), storedWeek)).toEqual({
      selection: { ...trending, window: 'week' },
      entryId: null,
      urlCorrection: { window: 'week' },
    });
  });

  it('puts the stored window in place of one Trending does not know', () => {
    const parsed = selectionFromParams(pm({ view: 'trending', window: 'month' }), ON);

    expect(parsed.selection).toEqual(trending);
    expect(parsed.urlCorrection).toEqual({ window: 'day' });
  });

  it('lets a named day win over a stored week', () => {
    const parsed = selectionFromParams(pm({ view: 'trending', window: 'day' }), storedWeek);

    expect(parsed.selection).toEqual(trending);
    expect(parsed.urlCorrection).toBeNull();
  });

  it('reads a window only on the Trending view', () => {
    expect(selectionFromParams(pm({ view: 'kept', window: 'week' }), ON)).toEqual({
      selection: { kind: 'kept' },
      entryId: null,
      urlCorrection: null,
    });
  });

  it('clears the window when a link selects another list', () => {
    expect(selectionQueryParams({ view: 'kept' }).window).toBeNull();
  });

  it('opens All items on an account that switched Trending off, and takes Trending out of the URL (D39)', () => {
    const parsed = selectionFromParams(pm({ view: 'trending', window: 'week' }), {
      trending: false,
      trendingWindow: 'day',
    });

    expect(parsed.selection).toEqual({ kind: 'all', unread: false });
    expect(parsed.urlCorrection).toEqual({ view: null, window: null });
  });

  it('asks for the window the selection carries', () => {
    expect(queryFromSelection({ ...trending, window: 'week' })).toEqual({
      view: 'trending',
      window: 'week',
    });
  });

  it('names a different list for a different window', () => {
    expect(sameSelection(trending, { ...trending, window: 'week' })).toBe(false);
  });

  it('offers no unread switch, no order, no mark-all-read and no mark-above-read', () => {
    expect(hasUnreadFilter(trending)).toBe(false);
    expect(hasListOrder(trending)).toBe(false);
    expect(markReadTarget(trending)).toBeNull();
    expect(offersMarkAboveRead(trending)).toBe(false);
    expect(offersMarkAboveRead({ kind: 'all', unread: false })).toBe(true);
  });

  it('never files its leads under one source', () => {
    expect(isSingleStreamView(trending)).toBe(true);
  });
});

describe('the selection readers (#2107)', () => {
  it('reads the unread filter only where the list offers it', () => {
    expect(isUnreadOnly({ kind: 'tag', id: 3, unread: true })).toBe(true);
    expect(isUnreadOnly({ kind: 'tag', id: 3, unread: false })).toBe(false);
    expect(isUnreadOnly({ kind: 'favorites' })).toBe(false);
  });

  it('reads the search term with its whole-word space, empty outside a search', () => {
    expect(searchTermOf({ kind: 'search', unread: false, term: 'punk ' })).toBe('punk ');
    expect(searchTermOf({ kind: 'all', unread: false })).toBe('');
  });

  it("reads Trending's window, null on any other list", () => {
    expect(trendingWindowOf({ kind: 'trending', window: 'week' })).toBe('week');
    expect(trendingWindowOf({ kind: 'all', unread: false })).toBeNull();
  });

  it("reads the tag's id, null on any other list", () => {
    expect(selectedTagIdOf({ kind: 'tag', id: 3, unread: false })).toBe(3);
    expect(selectedTagIdOf({ kind: 'subscription', id: 3, unread: false })).toBeNull();
  });

  it("reads the feed group's name, null on any other list", () => {
    expect(selectedGroupOf({ kind: 'group', group: 'tags', unread: false })).toBe('tags');
    expect(selectedGroupOf({ kind: 'all', unread: false })).toBeNull();
  });

  it("reads the saved search's id, null on the combined view and any other list", () => {
    expect(selectedSavedSearchIdOf({ kind: 'saved-search', id: 42, unread: false })).toBe(42);
    expect(selectedSavedSearchIdOf({ kind: 'saved-searches', unread: false })).toBeNull();
    expect(selectedSavedSearchIdOf({ kind: 'tag', id: 42, unread: false })).toBeNull();
  });
});

describe('identityKey (#2107)', () => {
  it('names what tells two lists of one kind apart, and nothing else', () => {
    expect(identityKey({ kind: 'all', unread: true })).toBe('');
    expect(identityKey({ kind: 'favorites' })).toBe('');
    expect(identityKey({ kind: 'tag', id: 3, unread: true })).toBe('3');
    expect(identityKey({ kind: 'saved-search', id: 7, unread: false })).toBe('7');
    expect(identityKey({ kind: 'network', unread: true, network: 'bluesky' })).toBe('bluesky');
    expect(
      identityKey({ kind: 'network', unread: true, network: 'bluesky', feedKind: 'profile' }),
    ).toBe('bluesky:profile');
    expect(identityKey({ kind: 'group', unread: true, group: 'tags' })).toBe('tags');
    expect(identityKey({ kind: 'trending', window: 'week' })).toBe('week');
    expect(identityKey({ kind: 'search', unread: false, term: 'punk ' })).toBe('punk ');
  });

  it('lets a list without an order read as newest first in a comparison', () => {
    expect(
      sameSelection({ kind: 'favorites' }, withListOrder({ kind: 'favorites' }, 'newest')),
    ).toBe(true);
    expect(sameSelection({ kind: 'favorites' }, { kind: 'kept' })).toBe(false);
  });
});
