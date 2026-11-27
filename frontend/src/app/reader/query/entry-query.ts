import { EntryQuery } from '../models';
import { LabelFilter } from '../labels/labels.models';
import {
  BySelectionKind,
  FilteredSelection,
  Selection,
  bySelectionKind,
  listOrderOf,
} from './query';

export function queryFromSelection(selection: Selection): EntryQuery | null {
  const query = bySelectionKind(VIEW_QUERY, selection);
  if (query === null) return null;
  return listOrderOf(selection) === 'oldest' ? { ...query, order: 'oldest' } : query;
}

const VIEW_QUERY: BySelectionKind<EntryQuery | null> = {
  favorites: (selection) => withLabel({ view: 'favorites' }, selection.label),
  kept: (selection) => withLabel({ view: 'kept' }, selection.label),
  shared: () => ({ view: 'shared' }),
  liked: () => ({ view: 'liked' }),
  viewed: () => ({ view: 'viewed' }),
  // The one list whose unread filter is not a view of its own: the ranked
  // feed IS the view, so the filter travels beside it (#710).
  'for-you': (selection) => unreadAsFlag({ view: 'for-you' }, selection),
  trending: (selection) => ({ view: 'trending', window: selection.window }),
  notifications: () => null,
  tag: (selection) => ({ view: unreadAsView(selection), tag: selection.id }),
  network: (selection) => ({
    view: unreadAsView(selection),
    network: selection.network,
    feedKind: selection.feedKind,
  }),
  group: (selection) => ({ view: unreadAsView(selection), group: selection.group }),
  subscription: (selection) => ({ view: unreadAsView(selection), subscription: selection.id }),
  all: (selection) => ({ view: unreadAsView(selection) }),
  'for-you-stream': (selection) => unreadAsFlag({ view: 'for-you-stream' }, selection),
  'saved-searches': (selection) => unreadAsFlag({ view: 'saved-searches' }, selection),
  // A single saved search reads the membership table by id, not a live
  // query, so it carries the id and takes the unread refinement like any list.
  'saved-search': (selection) =>
    unreadAsFlag({ view: 'all', savedSearchId: selection.id }, selection),
  'trending-story': (selection) => unreadAsFlag({ view: 'all', storyId: selection.id }, selection),
  search: (selection) => unreadAsFlag({ view: 'all', q: selection.term }, selection),
};

function unreadAsView(selection: FilteredSelection): EntryQuery['view'] {
  return selection.unread ? 'unread' : 'all';
}

function unreadAsFlag(query: EntryQuery, selection: FilteredSelection): EntryQuery {
  return selection.unread ? { ...query, unread: true } : query;
}

function withLabel(query: EntryQuery, label: LabelFilter | undefined): EntryQuery {
  return label === undefined ? query : { ...query, label };
}
