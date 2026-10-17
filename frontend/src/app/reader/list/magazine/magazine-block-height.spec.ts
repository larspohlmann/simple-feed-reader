import { BLOCK_HEIGHT } from './magazine-block';
import { blockHeight, quotingHeight, threadHeight } from './magazine-block-height';
import { aLinkCard, aQuotedPost, anEntry } from '../../../../testing/entry-testing';
import { aThreadPost } from '../../../../testing/thread-testing';
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
  it('reads the fixed table for every other kind', () => {
    expect(blockHeight('link', anEntry())).toBe(BLOCK_HEIGHT.link);
    expect(blockHeight('compact', anEntry())).toBe(BLOCK_HEIGHT.compact);
  });

  it('reads the entry for a quoting block', () => {
    const entry = post('Hi', aQuotedPost({ text: lines(5) }));

    expect(blockHeight('quoting', entry)).toBe(quotingHeight(entry));
  });

  it('reads the entry for a thread block', () => {
    const entry = anEntry({ thread: [aThreadPost()] });

    expect(blockHeight('thread', entry)).toBe(threadHeight(entry));
  });
});

describe('quotingHeight', () => {
  it('grows with the quoted text up to eight lines, then stops', () => {
    const three = quotingHeight(post('Hi', aQuotedPost({ text: lines(3) })));
    const eight = quotingHeight(post('Hi', aQuotedPost({ text: lines(8) })));
    const twenty = quotingHeight(post('Hi', aQuotedPost({ text: lines(20) })));

    expect(eight).toBeGreaterThan(three);
    expect(twenty).toBe(eight);
  });

  it('grows with the own text up to six lines, then stops', () => {
    const six = quotingHeight(post('x'.repeat(6 * 44)));

    expect(quotingHeight(post('x'.repeat(40 * 44)))).toBe(six);
    expect(quotingHeight(post('x'.repeat(2 * 44)))).toBeLessThan(six);
  });

  it('adds the quoted box to the post', () => {
    expect(quotingHeight(post('Hi'))).toBeGreaterThan(quotingHeight(post('Hi', null)));
  });

  it('adds the nested-quote line to the quoted box', () => {
    const nested = aQuotedPost({ nestedQuote: { url: 'https://n.example/p', handle: null } });

    expect(quotingHeight(post('Hi', nested))).toBeGreaterThan(quotingHeight(post('Hi')));
  });

  it('adds the post’s own media', () => {
    const withMedia = anEntry({ ...post('Hi'), media: [video(1920, 1080)] });

    expect(quotingHeight(withMedia)).toBeGreaterThan(quotingHeight(post('Hi')));
  });

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

  it('ignores link cards', () => {
    const withCards = anEntry({ ...post('Hi'), linkCards: [aLinkCard(), aLinkCard()] });

    expect(quotingHeight(withCards)).toBe(quotingHeight(post('Hi')));
  });
});

describe('threadHeight', () => {
  const threadOf = (...replies: ReturnType<typeof aThreadPost>[]) =>
    anEntry({ titleDerived: true, title: 'Hi', linkCards: [], thread: replies });

  it('is shorter with a text reply than with a carded one, both above the bare chrome', () => {
    const textOnly = threadHeight(threadOf(aThreadPost({ text: 'A reply' })));
    const carded = threadHeight(
      threadOf(aThreadPost({ text: 'A reply', linkCard: aLinkCard({ imageUrl: null }) })),
    );

    const chrome = 82;
    expect(textOnly).toBeLessThan(carded);
    expect(textOnly).toBeGreaterThan(chrome);
  });

  it('gives a card with a picture more height than a bare one', () => {
    const bare = threadHeight(threadOf(aThreadPost({ linkCard: aLinkCard({ imageUrl: null }) })));
    const pictured = threadHeight(
      threadOf(aThreadPost({ linkCard: aLinkCard({ imageUrl: 'https://i.test/a.jpg' }) })),
    );

    expect(pictured).toBeGreaterThan(bare);
  });

  it('grows with a reply’s text up to the post-text outlier clamp, then stops', () => {
    const three = threadHeight(threadOf(aThreadPost({ text: 'x'.repeat(3 * 44) })));
    const clamp = threadHeight(threadOf(aThreadPost({ text: 'x'.repeat(30 * 44) })));

    expect(clamp).toBeGreaterThan(three);
    expect(threadHeight(threadOf(aThreadPost({ text: 'x'.repeat(60 * 44) })))).toBe(clamp);
  });

  it('adds a titled root’s dek', () => {
    const titled = (excerpt: string) =>
      anEntry({ titleDerived: false, title: 'A title', excerpt, linkCards: [] });

    expect(threadHeight(titled('What the story is about.'))).toBeGreaterThan(
      threadHeight(titled('')),
    );
  });

  it('adds a media root’s own media', () => {
    const root = (media: EntryMediumDto[]) =>
      anEntry({ share: 'media', titleDerived: true, title: 'Hi', imageUrl: null, media });

    expect(threadHeight(root([video(1920, 1080)]))).toBeGreaterThan(threadHeight(root([])));
  });

  it('adds a quote root’s quoted box', () => {
    const root = (quote: QuotedPostDto | null) =>
      anEntry({ share: 'quote', titleDerived: true, title: 'Hi', imageUrl: null, quote });

    expect(threadHeight(root(aQuotedPost()))).toBeGreaterThan(threadHeight(root(null)));
  });

  it('adds a reply’s media under that reply', () => {
    expect(threadHeight(threadOf(aThreadPost({ media: [video(1920, 1080)] })))).toBeGreaterThan(
      threadHeight(threadOf(aThreadPost())),
    );
  });

  it('holds a pending root’s card place', () => {
    const root = (pending: boolean) =>
      anEntry({ share: 'link', titleDerived: true, title: 'Hi', linkCards: [], pending });

    expect(threadHeight(root(true))).toBeGreaterThan(threadHeight(root(false)));
  });
});
