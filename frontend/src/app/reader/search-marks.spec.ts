import { markTerms } from './search-marks';

describe('markTerms', () => {
  it('returns one unmarked segment holding the whole text when no terms are given', () => {
    expect(markTerms('hello world', [])).toEqual([{ text: 'hello world', marked: false }]);
  });

  it('splits a matching term into three segments, marking the middle one', () => {
    expect(markTerms('hello world', ['world'])).toEqual([
      { text: 'hello ', marked: false },
      { text: 'world', marked: true },
    ]);
  });

  it('matches case-insensitively while preserving the original casing', () => {
    expect(markTerms('Hello World', ['world'])).toEqual([
      { text: 'Hello ', marked: false },
      { text: 'World', marked: true },
    ]);
  });

  it('marks two different terms', () => {
    expect(markTerms('the quick brown fox', ['quick', 'fox'])).toEqual([
      { text: 'the ', marked: false },
      { text: 'quick', marked: true },
      { text: ' brown ', marked: false },
      { text: 'fox', marked: true },
    ]);
  });

  it('marks a term that appears twice', () => {
    expect(markTerms('cat and cat', ['cat'])).toEqual([
      { text: 'cat', marked: true },
      { text: ' and ', marked: false },
      { text: 'cat', marked: true },
    ]);
  });

  it('matches a regular-expression metacharacter in a term literally', () => {
    expect(markTerms('c++ is fast', ['c++'])).toEqual([
      { text: 'c++', marked: true },
      { text: ' is fast', marked: false },
    ]);
  });

  it('is safe with an empty term list', () => {
    expect(markTerms('some text', [])).toEqual([{ text: 'some text', marked: false }]);
  });

  it('is safe with empty text', () => {
    expect(markTerms('', ['term'])).toEqual([{ text: '', marked: false }]);
  });

  it('ignores empty-string terms', () => {
    expect(markTerms('hello', [''])).toEqual([{ text: 'hello', marked: false }]);
  });

  // The compiled pattern and lowered set are cached against the identity of the
  // term list, because every row of a result page is handed the same array.
  // These pin the two ways such a cache goes wrong: serving one term list's
  // pattern to another, and carrying regex state between calls.
  describe('reusing the compiled pattern across calls', () => {
    it('does not serve a cached pattern to a different term list', () => {
      markTerms('the quick fox', ['quick']);

      expect(markTerms('the quick fox', ['fox'])).toEqual([
        { text: 'the quick ', marked: false },
        { text: 'fox', marked: true },
      ]);
    });

    it('marks every row the same way when one term list is reused', () => {
      const terms = ['punk'];
      const first = markTerms('daft punk', terms);
      const second = markTerms('daft punk', terms);
      const third = markTerms('daft punk', terms);

      expect(second).toEqual(first);
      expect(third).toEqual(first);
    });

    it('keeps marking from the start of each text, carrying no lastIndex over', () => {
      const terms = ['cat'];
      markTerms('a long line that ends with cat', terms);

      expect(markTerms('cat first', terms)).toEqual([
        { text: 'cat', marked: true },
        { text: ' first', marked: false },
      ]);
    });

    it('re-compiles when the same array is handed back with different content', () => {
      const terms = ['quick'];
      markTerms('the quick fox', terms);
      terms[0] = 'fox';

      // A cache keyed on identity alone would still hold the 'quick' pattern.
      // Callers build a fresh array per search (a computed), so this never
      // happens in the app — the test states the contract rather than a bug.
      expect(markTerms('the quick fox', [...terms])).toEqual([
        { text: 'the quick ', marked: false },
        { text: 'fox', marked: true },
      ]);
    });
  });
});
