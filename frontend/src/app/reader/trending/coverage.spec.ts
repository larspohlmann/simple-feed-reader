import { anEntry } from '../../../testing/entry-testing';
import { EntryDto, TrendingCoverage } from '../models';
import { isStoryViewed, showsUnreadMark } from './coverage';

const lead = (coverage: Partial<TrendingCoverage> = {}, over: Partial<EntryDto> = {}): EntryDto =>
  anEntry({ coverage: { outletCount: 5, others: [], viewed: false, ...coverage }, ...over });

describe('isStoryViewed', () => {
  it('is false for a story nobody opened', () => {
    expect(isStoryViewed(lead({ others: [anEntry({ id: 2 })] }))).toBe(false);
  });

  it("follows the server's verdict, which counts social entries the card does not show", () => {
    expect(isStoryViewed(lead({ viewed: true }))).toBe(true);
  });

  it('is true once the lead was opened here', () => {
    expect(isStoryViewed(lead({}, { isViewed: true }))).toBe(true);
  });

  it('is true once another outlet was opened here', () => {
    expect(isStoryViewed(lead({ others: [anEntry({ id: 2, isViewed: true })] }))).toBe(true);
  });

  it('is false for an ordinary row', () => {
    expect(isStoryViewed(anEntry({ isViewed: true }))).toBe(false);
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
