import { anEntry, aStory, aStoryArticle } from '../../../../testing/entry-testing';
import { alsoFootOf } from './also-foot';

describe('alsoFootOf', () => {
  it("lists a row's duplicates under Also in", () => {
    const copy = anEntry({ id: 2 });

    expect(alsoFootOf(anEntry({ duplicates: [copy] }), null)).toEqual({
      copies: [copy],
      label: 'reader.alsoIn',
    });
  });

  it('lists nothing for a row without duplicates', () => {
    expect(alsoFootOf(anEntry(), null).copies).toEqual([]);
  });

  it('hands out the same empty list every time, so a footer input never changes identity', () => {
    expect(alsoFootOf(anEntry(), null).copies).toBe(alsoFootOf(anEntry({ id: 9 }), null).copies);
  });

  it("lists a Trending story's other outlets under Also covered by", () => {
    const lead = aStoryArticle();
    const other = aStoryArticle({ id: 3 });
    const story = aStory({ outletCount: 4, viewed: false, shareCount: 0, articles: [lead, other] });

    expect(alsoFootOf(lead, story)).toEqual({ copies: [other], label: 'reader.alsoCoveredBy' });
  });
});
