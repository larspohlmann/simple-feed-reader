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
});
