import { firstPreviewImage, textSnippet } from './preview-image';

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
