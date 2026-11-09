import { markReadTarget } from './mark-read-target';

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
  it('marks a network read by its name', () => {
    expect(
      markReadTarget({ kind: 'network', id: null, unread: false, network: 'mastodon' }),
    ).toEqual({
      scope: 'network',
      network: 'mastodon',
    });
    expect(markReadTarget({ kind: 'network', id: null, unread: false })).toBeNull();
  });
  it('marks a single saved search read by its id', () => {
    expect(markReadTarget({ kind: 'saved-search', id: 42, unread: false })).toEqual({
      scope: 'saved-search',
      id: 42,
    });
    expect(markReadTarget({ kind: 'saved-search', id: null, unread: false })).toBeNull();
  });
});
