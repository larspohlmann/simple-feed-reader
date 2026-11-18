import { anEntry, aStory, aStoryArticle } from '../../../testing/entry-testing';
import { EntryDto } from '../models';
import { withPatchedItem } from './entry-rows';
import { storyOf } from './list-item';

const viewed = (entry: EntryDto): EntryDto => ({ ...entry, isViewed: true });

describe('withPatchedItem (#2328)', () => {
  it('patches a targeted entry', () => {
    expect(withPatchedItem(anEntry({ id: 1 }), new Set([1]), viewed)).toMatchObject({
      id: 1,
      isViewed: true,
    });
  });

  it('patches a targeted copy inside an entry', () => {
    const row = anEntry({ id: 1, duplicates: [anEntry({ id: 2 })] });

    const patched = withPatchedItem(row, new Set([2]), viewed) as EntryDto;

    expect(patched.duplicates?.[0].isViewed).toBe(true);
  });

  it('keeps a story whose articles are not targeted', () => {
    const story = aStory({ articles: [aStoryArticle({ id: 1 }), aStoryArticle({ id: 2 })] });

    expect(withPatchedItem(story, new Set([9]), viewed)).toBe(story);
  });

  it('keeps a story whose targeted article the patch leaves as it was', () => {
    const story = aStory({ articles: [aStoryArticle({ id: 1 }), aStoryArticle({ id: 2 })] });

    expect(withPatchedItem(story, new Set([1]), (entry) => entry)).toBe(story);
  });

  it('patches only the targeted article of a story', () => {
    const story = aStory({ articles: [aStoryArticle({ id: 1 }), aStoryArticle({ id: 2 })] });

    const patched = storyOf(withPatchedItem(story, new Set([2]), viewed));

    expect(patched?.articles[1].isViewed).toBe(true);
    expect(patched?.articles[0]).toBe(story.articles[0]);
  });

  it('never patches the posts that share an article', () => {
    const share = anEntry({ id: 3 });
    const story = aStory({ articles: [aStoryArticle({ id: 1, shares: [share] })] });

    const patched = storyOf(withPatchedItem(story, new Set([1, 3]), viewed));

    expect(patched?.articles[0].isViewed).toBe(true);
    expect(patched?.articles[0].shares[0]).toBe(share);
  });
});
