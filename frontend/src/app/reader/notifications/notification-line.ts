import { NotificationGroupDto, NotificationKind } from './notification.models';

export interface LinePart {
  readonly text: string;
  readonly strong: boolean;
}

export type Translate = (key: string, params?: Record<string, unknown>) => string;

export const NOTIFICATION_GLYPH: Readonly<Record<NotificationKind, string>> = {
  like: 'favorite',
  repost: 'repeat',
  reply: 'chat_bubble',
  mention: 'alternate_email',
  quote: 'format_quote',
  follow: 'person_add',
  poll: 'ballot',
};

const MARK = '\u0000';

export function notificationLine(
  group: NotificationGroupDto,
  translate: Translate,
): readonly LinePart[] {
  if (group.kind === 'poll')
    return [{ text: translate('reader.notifications.poll'), strong: false }];

  const names = group.actors.map((actor) => actor.displayName ?? actor.handle);
  const named = Math.min(names.length, group.actorCount > 2 ? 2 : group.actorCount);
  const others = group.actorCount - named;
  const form =
    named === 1 ? 'one' : others === 0 ? 'two' : others === 1 ? 'twoAndOne' : 'twoAndOthers';
  const who = translate(`reader.notifications.who.${form}`, {
    first: MARK + names[0] + MARK,
    second: MARK + names[1] + MARK,
    count: others,
  });
  const number = group.actorCount > 1 ? 'other' : 'one';
  const action = group.onComment ? 'actionOnComment' : 'action';

  return translate(`reader.notifications.${action}.${group.kind}.${number}`, { who })
    .split(MARK)
    .map((text, index) => ({ text, strong: index % 2 === 1 }))
    .filter((part) => part.text !== '');
}
