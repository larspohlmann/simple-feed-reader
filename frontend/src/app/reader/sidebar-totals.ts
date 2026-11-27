import { FeedGroupName } from '../core/feed-group';
import { SocialNetworkName } from '../core/social-network';
import { TrendingUnopened } from './trending/trending.models';

export interface SidebarTotalsDto {
  favoritesCount: number;
  keptCount: number;
  sharedCount: number;
  viewedCount: number;
  unreadTotals: UnreadTotalsDto;
  /** Unopened Trending stories in the window the request asked for (#2017). */
  trending: TrendingUnopened;
  forYouStream: ForYouStreamTallyDto;
  notifications: NotificationCountDto[];
}

/** The For you stream's tally; the sidebar row shows only while it is available. */
export interface ForYouStreamTallyDto {
  available: boolean;
  unreadCount: number;
  entryCount: number;
}

export const NO_FOR_YOU_STREAM_TALLY: ForYouStreamTallyDto = {
  available: false,
  unreadCount: 0,
  entryCount: 0,
};

/** The sidebar poll's cheap payload (#720): unread and entry counts per feed
 *  plus the totals, without the feeds, tags or descriptions the
 *  full list carries. A feed absent from `subscriptions` has no entries. */
export interface SubscriptionCountsResponse extends SidebarTotalsDto {
  subscriptions: { id: number; unreadCount: number; entryCount: number }[];
}

export interface NotificationCountDto {
  network: SocialNetworkName;
  unseenCount: number;
}

/** Unread per scope, each article counted once within its scope (#1679). */
export interface UnreadTotalsDto {
  allItems: number;
  forYou: number;
  tags: { id: number; unreadCount: number }[];
  networks: { network: SocialNetworkName; unreadCount: number; profilesUnreadCount: number }[];
  groups: Record<FeedGroupName, number>;
}
