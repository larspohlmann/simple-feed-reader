import { EntryDto } from '../models';
import { SubscriptionsStore } from './subscriptions.store';

/** Counts a share only of an entry not yet shared; true when it counted, so a failure can take it back. */
export function countFirstShare(
  subscriptions: Pick<SubscriptionsStore, 'bumpShared'>,
  entry: Pick<EntryDto, 'isShared'> | null | undefined,
): boolean {
  if (entry?.isShared !== false) return false;
  subscriptions.bumpShared(1);
  return true;
}
