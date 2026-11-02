import { SelectedQuote } from '../selection-share/selected-quote';
import { quoteShareUrl } from './quote-share-url';

const quote = (text: string, anchorable = true): SelectedQuote => ({
  text,
  words: text.split(' ').length,
  anchorable,
});

describe('quoteShareUrl', () => {
  it('points a text fragment at a short quote', () => {
    expect(quoteShareUrl('https://a.example/story', quote('the quick brown fox'))).toBe(
      'https://a.example/story#:~:text=the%20quick%20brown%20fox',
    );
  });

  it('encodes the characters a text fragment reserves', () => {
    expect(quoteShareUrl('https://a.example/s', quote('well-known, salt & pepper'))).toBe(
      'https://a.example/s#:~:text=well%2Dknown%2C%20salt%20%26%20pepper',
    );
  });

  it('names a long quote by its first and last four words', () => {
    expect(
      quoteShareUrl('https://a.example/s', quote('one two three four five six seven eight nine')),
    ).toBe('https://a.example/s#:~:text=one%20two%20three%20four,six%20seven%20eight%20nine');
  });

  it('keeps a quote of eight words whole', () => {
    expect(
      quoteShareUrl('https://a.example/s', quote('one two three four five six seven eight')),
    ).toBe('https://a.example/s#:~:text=one%20two%20three%20four%20five%20six%20seven%20eight');
  });

  it('adds the directive after a fragment the URL already has', () => {
    expect(quoteShareUrl('https://a.example/s#part', quote('one two three'))).toBe(
      'https://a.example/s#part:~:text=one%20two%20three',
    );
  });

  it('shares the plain URL for a quote no fragment can anchor', () => {
    expect(quoteShareUrl('https://a.example/s', quote('one two three', false))).toBe(
      'https://a.example/s',
    );
  });
});
