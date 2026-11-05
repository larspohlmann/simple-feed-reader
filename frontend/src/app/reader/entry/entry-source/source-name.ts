import { EntryDto } from '../../models';
import { SubscriptionRows } from '../../state/subscription-rows';

/** The poster when someone other than the feed posted it, else the feed by its display name. */
export function sourceNameOf(
  entry: Pick<EntryDto, 'poster' | 'subscriptionId' | 'source'>,
  rows: SubscriptionRows,
): string {
  return entry.poster?.name ?? rows.find(entry.subscriptionId)?.title ?? entry.source;
}
