export type NotificationKind =
  'like' | 'repost' | 'reply' | 'comment_reply' | 'mention' | 'quote' | 'follow' | 'poll';

export interface NotificationActorDto {
  handle: string;
  displayName: string | null;
  avatarUrl: string | null;
  profileUrl: string;
}

export interface NotificationGroupDto {
  id: number;
  kind: NotificationKind;
  account: string;
  actors: NotificationActorDto[];
  actorCount: number;
  excerpt: string | null;
  answeredExcerpt: string | null;
  threadTitle: string | null;
  threadPlace: string | null;
  threadBy: string | null;
  linkUrl: string;
  latestAt: string;
  seen: boolean;
}

export interface NotificationsPageDto {
  groups: NotificationGroupDto[];
  nextCursor: number | null;
  scopeMissing: string[];
  needsReconnect: string[];
}
