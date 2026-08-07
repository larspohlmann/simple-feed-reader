import { entryImage, firstPreviewImage, textSnippet } from './preview-image';
import { EntryDto } from './models';

describe('firstPreviewImage', () => {
  it('returns the first https image src', () => {
    expect(
      firstPreviewImage(
        '<p>hi</p><img src="https://cdn.test/a.jpg"><img src="https://cdn.test/b.jpg">',
      ),
    ).toBe('https://cdn.test/a.jpg');
  });
  it('skips http and relative/data images', () => {
    expect(
      firstPreviewImage(
        '<img src="http://x/a.png"><img src="/rel.png"><img src="data:image/png;base64,AAAA">',
      ),
    ).toBeNull();
    expect(firstPreviewImage('<img src="https://ok.test/z.png">')).toBe('https://ok.test/z.png');
  });
  it('falls back to summary when content has none', () => {
    expect(firstPreviewImage(null, '<img src="https://s.test/s.jpg">')).toBe(
      'https://s.test/s.jpg',
    );
  });
  it('returns null for empty or image-less html', () => {
    expect(firstPreviewImage('', '')).toBeNull();
    expect(firstPreviewImage('<p>text only</p>')).toBeNull();
  });
  it('is safe on malformed html', () => {
    expect(() => firstPreviewImage('<img src=https://x <<< broken')).not.toThrow();
  });
});

describe('textSnippet', () => {
  it('strips tags to plain text', () => {
    expect(textSnippet('<p>Hello <b>world</b></p>')).toBe('Hello world');
  });
  it('collapses whitespace and handles null', () => {
    expect(textSnippet('  a\n\n  b  ')).toBe('a b');
    expect(textSnippet(null)).toBe('');
  });
});

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 't',
  url: null,
  author: null,
  summary: null,
  contentHtml: null,
  imageUrl: null,
  imageWidth: null,
  imageHeight: null,
  publishedAt: null,
  createdAt: 'x',
  subscriptionId: 1,
  source: 'S',
  faviconUrl: null,
  isRead: false,
  isFavorite: false,
  isKept: false,
  isViewed: false,
  ...over,
});

describe('entryImage', () => {
  it('prefers the persisted image and carries its dimensions', () => {
    expect(
      entryImage(entry({ imageUrl: 'https://i/a.jpg', imageWidth: 948, imageHeight: 474 })),
    ).toEqual({ url: 'https://i/a.jpg', width: 948, height: 474 });
  });

  it('falls back to an inline https img for archive rows', () => {
    expect(entryImage(entry({ contentHtml: '<img src="https://i/b.jpg">' }))).toEqual({
      url: 'https://i/b.jpg',
      width: null,
      height: null,
    });
  });

  it('rejects a non-https inline src', () => {
    expect(entryImage(entry({ contentHtml: '<img src="http://i/c.jpg">' }))).toBeNull();
  });

  it('returns null when there is no image anywhere', () => {
    expect(entryImage(entry())).toBeNull();
  });
});
