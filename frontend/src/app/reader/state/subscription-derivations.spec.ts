import { TagDto } from '../models';
import { makeSubscription } from '../testing/subscription.factory';
import { makeTag } from '../testing/tag.factory';
import { suggestedTags } from './subscription-derivations';

const news = makeTag(1, 'News');
const tech = makeTag(2, 'Tech');
const music = makeTag(3, 'Music');
const science = makeTag(4, 'Science');
const culture = makeTag(5, 'Culture');

describe('suggestedTags', () => {
  const subscriptions = [
    makeSubscription({ id: 10, createdAt: '2026-01-01T00:00:00Z', tags: [culture] }),
    makeSubscription({ id: 11, createdAt: '2026-03-01T00:00:00Z', tags: [news, tech] }),
    makeSubscription({ id: 12, createdAt: '2026-02-01T00:00:00Z', tags: [tech, music] }),
    makeSubscription({ id: 13, createdAt: '2026-04-01T00:00:00Z', tags: [science] }),
  ];

  const names = (tags: TagDto[]): string[] => tags.map((suggested) => suggested.name);

  it('lists the tags of the newest subscriptions first, each once, at most four', () => {
    expect(names(suggestedTags(subscriptions, new Set(), null))).toEqual([
      'Science',
      'News',
      'Tech',
      'Music',
    ]);
  });

  it('leaves out tags already selected', () => {
    expect(names(suggestedTags(subscriptions, new Set([4, 2]), null))).toEqual([
      'News',
      'Music',
      'Culture',
    ]);
  });

  it('skips the subscription being edited', () => {
    expect(names(suggestedTags(subscriptions, new Set(), 13))).toEqual([
      'News',
      'Tech',
      'Music',
      'Culture',
    ]);
  });

  it('suggests nothing when no subscription carries a tag', () => {
    expect(suggestedTags([makeSubscription()], new Set(), null)).toEqual([]);
  });
});
