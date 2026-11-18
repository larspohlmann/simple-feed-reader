import { anEntry, aStory, aStoryArticle } from '../../../testing/entry-testing';
import { isStoryViewed, showsUnreadMark } from './story';

describe('isStoryViewed', () => {
  it('is false for a story nobody opened', () => {
    const story = aStory({ articles: [aStoryArticle(), aStoryArticle({ id: 2 })] });

    expect(isStoryViewed(story)).toBe(false);
  });

  it("follows the server's verdict, which counts social entries the card does not show", () => {
    expect(isStoryViewed(aStory({ viewed: true }))).toBe(true);
  });

  it('is true once the lead was opened here', () => {
    expect(isStoryViewed(aStory({ articles: [aStoryArticle({ isViewed: true })] }))).toBe(true);
  });

  it('is true once another outlet was opened here', () => {
    const story = aStory({
      articles: [aStoryArticle(), aStoryArticle({ id: 2, isViewed: true })],
    });

    expect(isStoryViewed(story)).toBe(true);
  });
});

describe('showsUnreadMark', () => {
  it('marks an ordinary row by its own read state', () => {
    expect(showsUnreadMark(anEntry({ isHidden: false }), null)).toBe(true);
    expect(showsUnreadMark(anEntry({ isHidden: true }), null)).toBe(false);
  });

  it('marks a Trending lead by its story, whatever the lead row says', () => {
    expect(showsUnreadMark(anEntry({ isHidden: false }), aStory({ viewed: true }))).toBe(false);
    expect(showsUnreadMark(anEntry({ isHidden: true }), aStory())).toBe(true);
  });
});
