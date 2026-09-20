import { entryImage, entrySnippet } from './preview-image';
import { EntryDto } from './models';

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
