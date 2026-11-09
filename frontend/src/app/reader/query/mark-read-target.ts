import { MarkReadScope } from '../models';
import { FeedGroupName } from '../../core/feed-group';
import { NetworkFeedKind, SocialNetworkName } from '../../core/social-network';
import { BySelectionKind, Selection, bySelectionKind } from './query';

/** What "Mark all read" applies to for a selection, or null where the action
 *  doesn't apply. A discriminated union, not a bag with optional fields: id and
 *  term never coexist, and a bag would make every consumer re-assert the case. */
export type MarkReadTarget =
  | { scope: Extract<MarkReadScope, 'all'> }
  | { scope: Extract<MarkReadScope, 'feed' | 'tag'>; id: number }
  | {
      scope: Extract<MarkReadScope, 'network'>;
      network: SocialNetworkName;
      feedKind?: NetworkFeedKind;
    }
  | { scope: Extract<MarkReadScope, 'group'>; group: FeedGroupName }
  | { scope: 'search'; term: string }
  | { scope: 'for-you' }
  | { scope: 'saved-searches' }
  | { scope: 'saved-search'; id: number };

/** The targets the mark-read endpoint itself takes, sent as its body. */
export type ScopedMarkReadTarget = Extract<MarkReadTarget, { scope: MarkReadScope }>;

export function markReadTarget(selection: Selection): MarkReadTarget | null {
  return bySelectionKind(MARK_READ_TARGET, selection);
}

const MARK_READ_TARGET: BySelectionKind<MarkReadTarget | null> = {
  all: () => ({ scope: 'all' }),
  tag: (selection) => ({ scope: 'tag', id: selection.id }),
  subscription: (selection) => ({ scope: 'feed', id: selection.id }),
  network: (selection) => ({
    scope: 'network',
    network: selection.network,
    feedKind: selection.feedKind,
  }),
  group: (selection) => ({ scope: 'group', group: selection.group }),
  search: (selection) => ({ scope: 'search', term: selection.term }),
  // No id and no term: the list is the reader's own ranked feed, so the
  // endpoint needs nothing beyond who is asking (#710).
  'for-you': () => ({ scope: 'for-you' }),
  'saved-searches': () => ({ scope: 'saved-searches' }),
  'saved-search': (selection) => ({ scope: 'saved-search', id: selection.id }),
  favorites: () => null,
  kept: () => null,
  shared: () => null,
  viewed: () => null,
  trending: () => null,
};
