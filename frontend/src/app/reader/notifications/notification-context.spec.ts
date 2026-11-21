import { NotificationGroupDto } from './notification.models';
import { notificationContext } from './notification-context';

function group(overrides: Partial<NotificationGroupDto> = {}): NotificationGroupDto {
  return {
    id: 1,
    kind: 'reply',
    account: '@lars.bsky.social',
    actors: [],
    actorCount: 1,
    excerpt: 'Their text',
    answeredExcerpt: null,
    threadTitle: null,
    threadPlace: null,
    threadBy: null,
    onComment: false,
    linkUrl: 'https://social.example/p/1',
    entryId: null,
    latestAt: '2026-11-16T10:00:00Z',
    seen: true,
    ...overrides,
  };
}

describe('notificationContext', () => {
  it.each(['like', 'repost'] as const)('quotes your own post for a %s', (kind) => {
    const view = notificationContext(group({ kind, excerpt: 'My post' }));
    expect(view.replyText).toBeNull();
    expect(view.quoted).toBe('My post');
  });

  it('shows their text and quotes what it answered for a reply', () => {
    const view = notificationContext(group({ kind: 'reply', answeredExcerpt: 'My post' }));
    expect(view.replyText).toBe('Their text');
    expect(view.quoted).toBe('My post');
  });

  it('shows their text and quotes your post for a quote', () => {
    const view = notificationContext(group({ kind: 'quote', answeredExcerpt: 'My post' }));
    expect(view.replyText).toBe('Their text');
    expect(view.quoted).toBe('My post');
  });

  it('places a Lemmy reply under its post and community', () => {
    const view = notificationContext(
      group({ threadTitle: 'Self-hosting', threadPlace: 'c/selfhosted' }),
    );
    expect(view.place).toEqual({
      icon: 'article',
      by: null,
      title: 'Self-hosting',
      community: 'c/selfhosted',
    });
  });

  it('places a Bluesky reply in a thread by its author', () => {
    const view = notificationContext(group({ threadTitle: 'A thread', threadBy: 'Zoe' }));
    expect(view.place).toEqual({ icon: 'forum', by: 'Zoe', title: 'A thread', community: null });
  });

  it('has no place without a thread title', () => {
    expect(notificationContext(group({ threadBy: 'Zoe' })).place).toBeNull();
  });
});
