import { SubscriptionDto } from './models';

/** True when a CDK drag's payload is a feed row, duck-typed on `feedUrl` (the
 *  field only `SubscriptionDto` carries) rather than some other draggable a
 *  shared drop list accepts. Shared by the sidebar and Organise page. */
export function isSubscriptionDrag(data: unknown): data is SubscriptionDto {
  return !!data && typeof data === 'object' && 'feedUrl' in data;
}
