import { entryDek, entryHeadline, withoutTrailingLinks } from './entry-text';
import { anEntry } from '../../../testing/entry-testing';

describe('entryDek', () => {
  it('is the excerpt of an ordinary entry', () => {
    expect(entryDek(anEntry({ excerpt: 'Some copy' }))).toBe('Some copy');
  });

  it('is empty for a post, whose text is already its headline', () => {
    expect(entryDek(anEntry({ excerpt: 'Some copy', titleDerived: true }))).toBe('');
  });
});

describe('entryHeadline', () => {
  it('is empty for an untitled entry', () => {
    expect(entryHeadline(anEntry({ title: '', excerpt: '', titleDerived: true }))).toBe('');
    expect(entryHeadline(anEntry({ title: '', excerpt: '', titleDerived: false }))).toBe('');
  });

  it('is the title of an ordinary entry', () => {
    expect(entryHeadline(anEntry({ title: 'Real', excerpt: 'Body', titleDerived: false }))).toBe(
      'Real',
    );
  });

  it('is the excerpt of a post, whose title only repeats it', () => {
    expect(
      entryHeadline(anEntry({ title: 'Body', excerpt: 'Body and more', titleDerived: true })),
    ).toBe('Body and more');
  });

  it('falls back to the derived title when a post has no excerpt', () => {
    expect(entryHeadline(anEntry({ title: 'null', excerpt: '', titleDerived: true }))).toBe('null');
  });
});

describe('withoutTrailingLinks', () => {
  it.each([
    ['a shortened link', 'Texas issues air permit politi.co/3WPZZF1', 'Texas issues air permit'],
    ['a link the excerpt cut off', 'could do exactly that. https://www.', 'could do exactly that.'],
    [
      'a run of links and a trailing newline',
      'Read this https://a.test/x slate.trib.al/PYBaii7\n',
      'Read this',
    ],
    ['a text that is only a link', 'https://a.test/only', ''],
    [
      'a link after line breaks the text keeps',
      'Line one\n\nLine  two https://a.test/x',
      'Line one\n\nLine  two',
    ],
  ])('drops %s', (_case, text, expected) => {
    expect(withoutTrailingLinks(text)).toBe(expected);
  });

  it.each([
    ['a link inside the sentence', 'Read https://a.test/x and comment'],
    ['a link followed by a hashtag', 'Outages hit Apple https://heise.de/x #Apple'],
    ['a dotted word without a path', 'Built with Node.js'],
  ])('keeps %s', (_case, text) => {
    expect(withoutTrailingLinks(text)).toBe(text);
  });
});
