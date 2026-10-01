import { entryImage, entrySnippet, renditionSrcset } from './preview-image';
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
});

describe('renditionSrcset', () => {
  it('lists the renditions narrowest first as width candidates', () => {
    expect(
      renditionSrcset([
        { url: 'https://i/a-848.jpg', width: 848 },
        { url: 'https://i/a-424.jpg', width: 424 },
      ]),
    ).toBe('https://i/a-424.jpg 424w, https://i/a-848.jpg 848w');
  });

  it('keeps the first rendition of a repeated width', () => {
    expect(
      renditionSrcset([
        { url: 'https://i/a-424.webp', width: 424 },
        { url: 'https://i/a-424.jpg', width: 424 },
      ]),
    ).toBe('https://i/a-424.webp 424w');
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
