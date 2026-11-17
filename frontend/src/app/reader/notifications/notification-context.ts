import { NotificationGroupDto } from './notification.models';

export interface NotificationPlace {
  readonly icon: 'article' | 'forum';
  readonly by: string | null;
  readonly title: string;
  readonly community: string | null;
}

export interface NotificationContextView {
  readonly replyText: string | null;
  readonly quoted: string | null;
  readonly place: NotificationPlace | null;
}

function placeOf(group: NotificationGroupDto): NotificationPlace | null {
  if (group.threadTitle === null) return null;
  return {
    icon: group.threadBy === null ? 'article' : 'forum',
    by: group.threadBy,
    title: group.threadTitle,
    community: group.threadPlace,
  };
}

export function notificationContext(group: NotificationGroupDto): NotificationContextView {
  const place = placeOf(group);
  if (group.kind === 'like' || group.kind === 'repost')
    return { replyText: null, quoted: group.excerpt, place };
  return { replyText: group.excerpt, quoted: group.answeredExcerpt, place };
}
