import { anEntry } from '../../../testing/entry-testing';
import { EntryDto } from '../models';
import { isStoryViewed, showsUnreadMark } from './story';
import { TrendingStory } from './trending.models';

const lead = (story: Partial<TrendingStory> = {}, over: Partial<EntryDto> = {}): EntryDto =>
  anEntry({ story: { outletCount: 5, viewed: false, ...story }, ...over });

describe('isStoryViewed', () => {
  it('is false for a story nobody opened', () => {
    expect(isStoryViewed(lead({}, { duplicates: [anEntry({ id: 2 })] }))).toBe(false);
  });

  it("follows the server's verdict, which counts social entries the card does not show", () => {
    expect(isStoryViewed(lead({ viewed: true }))).toBe(true);
  });

  it('is true once the lead was opened here', () => {
    expect(isStoryViewed(lead({}, { isViewed: true }))).toBe(true);
  });

  it('is true once another outlet was opened here', () => {
    expect(isStoryViewed(lead({}, { duplicates: [anEntry({ id: 2, isViewed: true })] }))).toBe(
      true,
    );
  });

  it('is false for an ordinary row, whatever its duplicates say', () => {
    const row = anEntry({ isViewed: true, duplicates: [anEntry({ id: 2, isViewed: true })] });

    expect(isStoryViewed(row)).toBe(false);
  });
});

describe('showsUnreadMark', () => {
  it('marks an ordinary row by its own read state', () => {
    expect(showsUnreadMark(anEntry({ isHidden: false }))).toBe(true);
    expect(showsUnreadMark(anEntry({ isHidden: true }))).toBe(false);
  });

  it('marks a Trending lead by its story, whatever the lead row says', () => {
    expect(showsUnreadMark(lead({ viewed: true }, { isHidden: false }))).toBe(false);
    expect(showsUnreadMark(lead({}, { isHidden: true }))).toBe(true);
  });
});
