import { entryImage, entrySnippet, renditionSrcset, widestRenditionWidth } from './preview-image';
import { EntryDto } from '../models';

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 't',
  url: null,
  author: null,
  summary: null,
  excerpt: '',
  imageUrl: null,
  imageWidth: null,
  imageHeight: null,
  imageRenditions: [],
  media: [],
  attachments: [],
  categories: [],
  publishedAt: null,
  createdAt: 'x',
  subscriptionId: 1,
  source: 'S',
  faviconUrl: null,
  isHidden: false,
  isFavorite: false,
  isKept: false,
  isViewed: false,
  discussionUrl: null,
  comments: null,
  ...over,
});

describe('entrySnippet', () => {
  it('returns the server-supplied excerpt', () => {
    expect(entrySnippet(entry({ excerpt: 'Some copy' }))).toBe('Some copy');
  });

  it('returns an empty string when the entry has none', () => {
    expect(entrySnippet(entry())).toBe('');
  });
});

describe('entryImage', () => {
  it('reads the persisted image and carries its dimensions', () => {
    expect(
      entryImage(entry({ imageUrl: 'https://i/a.jpg', imageWidth: 948, imageHeight: 474 })),
    ).toEqual({ url: 'https://i/a.jpg', width: 948, height: 474 });
  });

  it('returns null when the entry has no image', () => {
    expect(entryImage(entry())).toBeNull();
  });

  const ladderTo1920 = [
    { url: 'https://i/a-768.jpg', width: 768 },
    { url: 'https://i/a-1920.jpg', width: 1920 },
  ];

  it('takes the width of a ladder that tops the lead image, scaling the height with it', () => {
    expect(
      entryImage(
        entry({
          imageUrl: 'https://i/a.jpg',
          imageWidth: 367,
          imageHeight: 245,
          imageRenditions: ladderTo1920,
        }),
      ),
    ).toEqual({ url: 'https://i/a.jpg', width: 1920, height: 1282 });
  });

  it('keeps an unknown height unknown when the ladder tops the lead image', () => {
    expect(
      entryImage(
        entry({ imageUrl: 'https://i/a.jpg', imageWidth: 367, imageRenditions: ladderTo1920 }),
      ),
    ).toEqual({ url: 'https://i/a.jpg', width: 1920, height: null });
  });

  it('keeps the dimensions of a lead image that declares none, whatever the ladder', () => {
    expect(
      entryImage(entry({ imageUrl: 'https://i/a.jpg', imageRenditions: ladderTo1920 })),
    ).toEqual({ url: 'https://i/a.jpg', width: null, height: null });
  });

  it('keeps the dimensions of a lead image at least as wide as its ladder', () => {
    expect(
      entryImage(
        entry({
          imageUrl: 'https://i/a.jpg',
          imageWidth: 1920,
          imageHeight: 1280,
          imageRenditions: ladderTo1920,
        }),
      ),
    ).toEqual({ url: 'https://i/a.jpg', width: 1920, height: 1280 });
  });
});

describe('renditionSrcset', () => {
  it('lists the renditions as width candidates, in the order served', () => {
    expect(
      renditionSrcset([
        { url: 'https://i/a-424.jpg', width: 424 },
        { url: 'https://i/a-848.jpg', width: 848 },
      ]),
    ).toBe('https://i/a-424.jpg 424w, https://i/a-848.jpg 848w');
  });

  it('keeps the commas inside a transform url', () => {
    const url =
      'https://substackcdn.com/image/fetch/$s_!v2GA!,w_424,c_limit,f_auto/https%3A%2F%2Fs3%2Fa.jpeg';
    expect(renditionSrcset([{ url, width: 424 }])).toBe(`${url} 424w`);
  });

  it('is null without renditions', () => {
    expect(renditionSrcset([])).toBeNull();
    expect(renditionSrcset(undefined)).toBeNull();
  });
});

describe('widestRenditionWidth', () => {
  it('is the width of the last, widest rung', () => {
    expect(
      widestRenditionWidth([
        { url: 'https://x/a-424.jpg', width: 424 },
        { url: 'https://x/a-848.jpg', width: 848 },
        { url: 'https://x/a-1696.jpg', width: 1696 },
      ]),
    ).toBe(1696);
  });

  it('is null without renditions', () => {
    expect(widestRenditionWidth([])).toBeNull();
    expect(widestRenditionWidth(undefined)).toBeNull();
  });
});
