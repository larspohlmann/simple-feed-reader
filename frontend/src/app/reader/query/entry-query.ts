import { EntryQuery } from '../models';
import { Selection, SelectionKind, listOrderOf } from './query';

export function queryFromSelection(selection: Selection): EntryQuery {
  const query = viewQuery(selection);
  return listOrderOf(selection) === 'oldest' ? { ...query, order: 'oldest' } : query;
}

function viewQuery(selection: Selection): EntryQuery {
  return VIEW_QUERY[selection.kind](selection);
}

const VIEW_QUERY: Record<SelectionKind, (selection: Selection) => EntryQuery> = {
  favorites: () => ({ view: 'favorites' }),
  kept: () => ({ view: 'kept' }),
  shared: () => ({ view: 'shared' }),
  viewed: () => ({ view: 'viewed' }),
  // The one list whose unread filter is not a view of its own: the ranked
  // feed IS the view, so the filter travels beside it (#710).
  'for-you': (selection) => withUnread({ view: 'for-you' }, selection.unread),
  trending: (selection) => ({ view: 'trending', window: selection.window }),
  tag: (selection) => ({ view: listView(selection), tag: selection.id ?? undefined }),
  network: (selection) => ({
    view: listView(selection),
    network: selection.network,
    feedKind: selection.feedKind,
  }),
  group: (selection) => ({ view: listView(selection), group: selection.group }),
  subscription: (selection) => ({
    view: listView(selection),
    subscription: selection.id ?? undefined,
  }),
  all: (selection) => ({ view: listView(selection) }),
  'saved-searches': (selection) => withUnread({ view: 'saved-searches' }, selection.unread),
  // A single saved search reads the membership table by id, not a live
  // query, so it carries the id and takes the unread refinement like any list.
  'saved-search': (selection) =>
    withUnread({ view: 'all', savedSearchId: selection.id ?? undefined }, selection.unread),
  search: (selection) => withUnread({ view: 'all', q: selection.term }, selection.unread),
};

function withUnread(query: EntryQuery, unread: boolean): EntryQuery {
  return unread ? { ...query, unread: true } : query;
}

function listView(selection: Selection): EntryQuery['view'] {
  return selection.unread ? 'unread' : 'all';
}
