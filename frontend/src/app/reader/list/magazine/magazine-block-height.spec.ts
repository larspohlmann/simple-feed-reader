import { BLOCK_HEIGHT } from './magazine-block';
import { blockHeight, quotingHeight } from './magazine-block-height';
import { aLinkCard, aQuotedPost, anEntry } from '../../../../testing/entry-testing';
import { EntryMediumDto } from '../../entry-medium';
import { QuotedPostDto } from '../../quoted-post/quoted-post';

const post = (title: string, quote: QuotedPostDto | null = aQuotedPost()) =>
  anEntry({ share: 'quote', titleDerived: true, title, quote });
const lines = (count: number) => Array.from({ length: count }, () => 'line').join('\n');
const image = (url: string): EntryMediumDto => ({ url, kind: 'image', width: 800, height: 1000 });
const video = (width: number, height: number): EntryMediumDto => ({
  url: 'https://v.example/p.m3u8',
  kind: 'video',
  width,
  height,
});

describe('blockHeight', () => {
  // Red if: the fixed table is bypassed for other kinds.
  it('reads the fixed table for every other kind', () => {
    expect(blockHeight('link', anEntry())).toBe(BLOCK_HEIGHT.link);
    expect(blockHeight('compact', anEntry())).toBe(BLOCK_HEIGHT.compact);
  });

  // Red if: blockHeight stops delegating 'quoting' to quotingHeight.
  it('reads the entry for a quoting block', () => {
    const entry = post('Hi', aQuotedPost({ text: lines(5) }));

    expect(blockHeight('quoting', entry)).toBe(quotingHeight(entry));
  });
});

describe('quotingHeight', () => {
  // Red if: the quote's text is ignored, or its 8-line cap is removed.
  it('grows with the quoted text up to eight lines, then stops', () => {
    const three = quotingHeight(post('Hi', aQuotedPost({ text: lines(3) })));
    const eight = quotingHeight(post('Hi', aQuotedPost({ text: lines(8) })));
    const twenty = quotingHeight(post('Hi', aQuotedPost({ text: lines(20) })));

    expect(eight).toBeGreaterThan(three);
    expect(twenty).toBe(eight);
  });

  // Red if: a long line is not wrapped by characters, or the 6-line cap is removed.
  it('grows with the own text up to six lines, then stops', () => {
    const six = quotingHeight(post('x'.repeat(6 * 44)));

    expect(quotingHeight(post('x'.repeat(40 * 44)))).toBe(six);
    expect(quotingHeight(post('x'.repeat(2 * 44)))).toBeLessThan(six);
  });

  // Red if: the quote is left out of the sum.
  it('adds the quoted box to the post', () => {
    expect(quotingHeight(post('Hi'))).toBeGreaterThan(quotingHeight(post('Hi', null)));
  });

  // Red if: the post's own media is left out of the sum.
  it('adds the post’s own media', () => {
    const withMedia = anEntry({ ...post('Hi'), media: [video(1920, 1080)] });

    expect(quotingHeight(withMedia)).toBeGreaterThan(quotingHeight(post('Hi')));
  });

  // Red if: the ratio is ignored, or the height cap is removed.
  it('gives a portrait video more height than a landscape one, capped', () => {
    const quoteWith = (width: number, height: number) =>
      post('Hi', aQuotedPost({ media: [video(width, height)] }));
    const landscape = quotingHeight(quoteWith(1920, 1080));
    const portrait = quotingHeight(quoteWith(1080, 1920));
    const tallest = quotingHeight(quoteWith(100, 10_000));

    expect(portrait).toBeGreaterThan(landscape);
    expect(tallest).toBeGreaterThanOrEqual(portrait);
    expect(quotingHeight(quoteWith(10, 100_000))).toBe(tallest);
  });

  // Red if: a grid is sized by its first item's ratio instead of one 16:9 block.
  it('counts a grid of two to four as one 16:9 block', () => {
    const quoteWith = (...media: EntryMediumDto[]) => post('Hi', aQuotedPost({ media }));
    const widescreen: EntryMediumDto = { ...image('w'), width: 1920, height: 1080 };

    expect(quotingHeight(quoteWith(image('a'), image('b')))).toBe(
      quotingHeight(quoteWith(widescreen)),
    );
    expect(quotingHeight(quoteWith(image('a'), image('b'), image('c'), image('d')))).toBe(
      quotingHeight(quoteWith(widescreen)),
    );
  });

  // Red if: link cards are counted.
  it('ignores link cards', () => {
    const withCards = anEntry({ ...post('Hi'), linkCards: [aLinkCard(), aLinkCard()] });

    expect(quotingHeight(withCards)).toBe(quotingHeight(post('Hi')));
  });
});
