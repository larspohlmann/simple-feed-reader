import { MarkReadScope } from '../models';
import { FeedGroupName } from '../../core/feed-group';
import { NetworkFeedKind, SocialNetworkName } from '../../core/social-network';
import { Selection, SelectionKind } from './query';

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
  return MARK_READ_TARGET[selection.kind]?.(selection) ?? null;
}

const MARK_READ_TARGET: Partial<
  Record<SelectionKind, (selection: Selection) => MarkReadTarget | null>
> = {
  all: () => ({ scope: 'all' }),
  tag: idScoped('tag'),
  network: (selection) =>
    selection.network
      ? { scope: 'network', network: selection.network, feedKind: selection.feedKind }
      : null,
  group: (selection) => (selection.group ? { scope: 'group', group: selection.group } : null),
  subscription: idScoped('feed'),
  search: (selection) => (selection.term ? { scope: 'search', term: selection.term } : null),
  // No id and no term: the list is the reader's own ranked feed, so the
  // endpoint needs nothing beyond who is asking (#710).
  'for-you': () => ({ scope: 'for-you' }),
  'saved-searches': () => ({ scope: 'saved-searches' }),
  'saved-search': idScoped('saved-search'),
};

function idScoped(
  scope: Extract<MarkReadTarget, { id: number }>['scope'],
): (selection: Selection) => MarkReadTarget | null {
  return (selection) => (selection.id != null ? { scope, id: selection.id } : null);
}
