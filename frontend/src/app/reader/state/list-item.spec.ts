import { anEntry, aStory, aStoryArticle } from '../../../testing/entry-testing';
import { articlesOf, isStory, leadOf, storyOf } from './list-item';

describe('list items', () => {
  it('leads an entry with itself', () => {
    const entry = anEntry({ id: 5 });

    expect(leadOf(entry)).toBe(entry);
  });

  it('leads a story with its first article', () => {
    const story = aStory({ articles: [aStoryArticle({ id: 8 }), aStoryArticle({ id: 9 })] });

    expect(leadOf(story).id).toBe(8);
  });

  it('lists every article of a story', () => {
    const story = aStory({
      articles: [aStoryArticle({ id: 1 }), aStoryArticle({ id: 2 }), aStoryArticle({ id: 3 })],
    });

    expect(articlesOf(story)).toHaveLength(3);
  });

  it('lists an entry as its own only article', () => {
    const entry = anEntry({ id: 5 });

    expect(articlesOf(entry)).toEqual([entry]);
  });

  it('does not take an entry for a story', () => {
    expect(isStory(anEntry())).toBe(false);
  });

  it('has no story behind an entry', () => {
    expect(storyOf(anEntry())).toBeNull();
  });

  it('answers the story itself for a story', () => {
    const story = aStory({ id: 4 });

    expect(storyOf(story)).toBe(story);
  });
});
