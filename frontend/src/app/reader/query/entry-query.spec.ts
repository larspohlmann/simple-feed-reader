import { queryFromSelection } from './entry-query';

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
  it("maps a network's view through the unread toggle", () => {
    expect(
      queryFromSelection({ kind: 'network', id: null, unread: true, network: 'bluesky' }),
    ).toEqual({ view: 'unread', network: 'bluesky' });
  });
  it('maps curated views directly', () => {
    expect(queryFromSelection({ kind: 'favorites', id: null, unread: false })).toEqual({
      view: 'favorites',
    });
    expect(queryFromSelection({ kind: 'kept', id: null, unread: false })).toEqual({ view: 'kept' });
    expect(queryFromSelection({ kind: 'shared', id: null, unread: false })).toEqual({
      view: 'shared',
    });
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
  it('routes a single saved-search selection to its id', () => {
    expect(queryFromSelection({ kind: 'saved-search', id: 42, unread: false })).toEqual({
      view: 'all',
      savedSearchId: 42,
    });
  });
  it('carries unread on a single saved-search selection', () => {
    expect(queryFromSelection({ kind: 'saved-search', id: 42, unread: true })).toEqual({
      view: 'all',
      savedSearchId: 42,
      unread: true,
    });
  });
});
