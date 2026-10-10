import { SubscriptionDto, TagDto } from './models';

/** True when a CDK drag's payload is a feed row, duck-typed on `feedUrl` (the
 *  field only `SubscriptionDto` carries) rather than some other draggable a
 *  shared drop list accepts. Shared by the sidebar and Organise page. */
export function isSubscriptionDrag(data: unknown): data is SubscriptionDto {
  return !!data && typeof data === 'object' && 'feedUrl' in data;
}

/** True when a CDK drag's payload is a tag header — duck-typed on `color`, the
 *  only field `TagDto` carries among this app's draggables. Lets a drop list that
 *  also accepts feed rows (Organise's tag header, #659) tell the drags apart. */
export function isTagDrag(data: unknown): data is TagDto {
  return !!data && typeof data === 'object' && 'color' in data && !isSubscriptionDrag(data);
}
