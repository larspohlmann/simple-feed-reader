import { anEntry } from '../../../../testing/entry-testing';
import { alsoFootOf } from './also-foot';

describe('alsoFootOf', () => {
  it("lists a row's duplicates under Also in", () => {
    const copy = anEntry({ id: 2 });

    expect(alsoFootOf(anEntry({ duplicates: [copy] }))).toEqual({
      copies: [copy],
      label: 'reader.alsoIn',
    });
  });

  it('lists nothing for a row without duplicates', () => {
    expect(alsoFootOf(anEntry()).copies).toEqual([]);
  });

  it('hands out the same empty list every time, so a footer input never changes identity', () => {
    expect(alsoFootOf(anEntry()).copies).toBe(alsoFootOf(anEntry({ id: 9 })).copies);
  });

  it("lists a Trending story's other outlets under Also covered by", () => {
    const other = anEntry({ id: 3 });
    const lead = anEntry({
      duplicates: [anEntry({ id: 2 })],
      coverage: { outletCount: 4, others: [other], viewed: false },
    });

    expect(alsoFootOf(lead)).toEqual({ copies: [other], label: 'reader.alsoCoveredBy' });
  });
});
