import { entryDek, entryHeadline } from './entry-text';
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
