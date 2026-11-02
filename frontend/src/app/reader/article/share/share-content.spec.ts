import { SelectedQuote } from '../selection-share/selected-quote';
import {
  QUOTE_MAX,
  clipboardText,
  clippedAtWord,
  linkContent,
  quoteContent,
  sharedLink,
  sharedPage,
} from './share-content';

const PAGE = { url: 'https://a.example/s', title: 'A story' };
const QUOTE: SelectedQuote = { text: 'one two three', words: 3, anchorable: true };
const FRAGMENT = 'https://a.example/s#:~:text=one%20two%20three';

describe('share content', () => {
  it('shares a page without its tracking parameters', () => {
    expect(sharedPage('https://example.com/a?utm_source=rss&id=1', 'First')).toEqual({
      url: 'https://example.com/a?id=1',
      title: 'First',
    });
  });

  it('shares a page by its title and URL', () => {
    expect(linkContent(PAGE)).toEqual({
      url: PAGE.url,
      title: 'A story',
      text: 'A story https://a.example/s',
      quote: null,
    });
    expect(linkContent({ ...PAGE, title: '' }).text).toBe(PAGE.url);
  });

  it('shares a quote in typographic quotes with the link that points at it', () => {
    expect(quoteContent(PAGE, QUOTE)).toEqual({
      url: FRAGMENT,
      title: 'A story',
      text: `“one two three” ${FRAGMENT}`,
      quote: '“one two three”',
    });
  });

  it('copies the link alone, or the quote above its link', () => {
    expect(clipboardText(linkContent(PAGE))).toBe(PAGE.url);
    expect(clipboardText(quoteContent(PAGE, QUOTE))).toBe(`“one two three”\n\n${FRAGMENT}`);
  });

  it('hands the composer the link alone, or the quote with the link that points at it', () => {
    expect(sharedLink(PAGE, null)).toEqual({ ...PAGE, quote: null });
    expect(sharedLink(PAGE, QUOTE)).toEqual({
      url: FRAGMENT,
      title: 'A story',
      quote: 'one two three',
    });
  });

  it('clips a quote past the most the server takes at a word', () => {
    const long: SelectedQuote = { text: 'word '.repeat(300).trim(), words: 300, anchorable: false };

    const sent = sharedLink(PAGE, long).quote!;

    expect(sent.length).toBeLessThanOrEqual(QUOTE_MAX);
    expect(sent.endsWith('word…')).toBe(true);
  });

  it('clips at the last space before the limit', () => {
    expect(clippedAtWord('one two three', 9)).toBe('one two…');
    expect(clippedAtWord('one two', 9)).toBe('one two');
  });
});
