import { NotificationCountDto } from '../models';
import {
  NO_NOTIFICATION_COUNTS,
  notificationCountsFrom,
  sameNotificationCounts,
  totalUnseen,
  unseenNetworks,
} from './notification-counts';

const mixed = (): NotificationCountDto[] => [
  { network: 'mastodon', unseenCount: 2 },
  { network: 'lemmy', unseenCount: 0 },
  { network: 'bluesky', unseenCount: 4 },
];

describe('notification counts', () => {
  it('reads the counts per network', () => {
    const counts = notificationCountsFrom([
      { network: 'bluesky', unseenCount: 4 },
      { network: 'mastodon', unseenCount: 2 },
    ]);

    expect(counts.get('bluesky')).toBe(4);
    expect(counts.get('lemmy')).toBeUndefined();
  });

  it('lists only networks with unseen notifications, in network order', () => {
    expect(unseenNetworks(notificationCountsFrom(mixed()))).toEqual([
      { network: 'bluesky', unseenCount: 4 },
      { network: 'mastodon', unseenCount: 2 },
    ]);
  });

  it('totals the unseen across networks', () => {
    expect(totalUnseen(notificationCountsFrom(mixed()))).toBe(6);
    expect(totalUnseen(NO_NOTIFICATION_COUNTS)).toBe(0);
  });

  it('compares by value', () => {
    expect(
      sameNotificationCounts(notificationCountsFrom(mixed()), notificationCountsFrom(mixed())),
    ).toBe(true);
    expect(
      sameNotificationCounts(
        notificationCountsFrom(mixed()),
        notificationCountsFrom([{ network: 'bluesky', unseenCount: 5 }]),
      ),
    ).toBe(false);
  });
});
