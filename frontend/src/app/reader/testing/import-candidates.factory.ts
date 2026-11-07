import {
  CollectionCandidate,
  FollowCandidate,
  ImportCandidates,
} from '../feeds/social-picker/social-import.service';

export function follow(overrides: Partial<FollowCandidate> = {}): FollowCandidate {
  return {
    identity: 'did:plc:bob',
    handle: '@bob.bsky.social',
    displayName: 'Bob',
    avatarUrl: null,
    feedUrl: 'https://bsky.app/profile/did:plc:bob/rss',
    state: 'new',
    subscriptionId: null,
    ...overrides,
  };
}

export const CAROL = follow({
  identity: 'did:plc:carol',
  handle: '@carol.bsky.social',
  displayName: 'Carol',
  feedUrl: 'https://bsky.app/profile/did:plc:carol/rss',
  state: 'subscribed',
  subscriptionId: 9,
});
export const DAVE = follow({
  identity: 'did:plc:dave',
  handle: 'did:plc:dave',
  displayName: 'Dave’s posts',
  feedUrl: 'https://bsky.app/profile/did:plc:dave/rss',
  state: 'no_longer_followed',
  subscriptionId: 10,
});

export function manyFollows(count: number): FollowCandidate[] {
  return Array.from({ length: count }, (_, index) =>
    follow({ identity: `did:plc:${index}`, feedUrl: `https://bsky.app/${index}/rss` }),
  );
}

export function collection(overrides: Partial<CollectionCandidate> = {}): CollectionCandidate {
  return {
    kind: 'list',
    id: 'at://list/a',
    name: 'News',
    state: 'new',
    subscriptionId: null,
    ...overrides,
  };
}

export const HOME = collection({ kind: 'home', id: null, name: 'Home' });
export const DISCOVER = collection({ kind: 'custom_feed', id: 'at://feed/a', name: 'Discover' });

export function candidates(overrides: Partial<ImportCandidates> = {}): ImportCandidates {
  return { follows: [], lists: [], customFeeds: [], tags: [], truncated: false, ...overrides };
}
