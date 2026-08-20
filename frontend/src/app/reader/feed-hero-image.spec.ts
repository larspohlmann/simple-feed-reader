import { feedHeroImage } from './feed-hero-image';
import { EntryDto } from './models';

function entry(over: Partial<EntryDto> = {}): EntryDto {
  return {
    id: 1,
    title: 'T',
    url: 'https://ex.test/a',
    author: null,
    summary: null,
    contentHtml: null,
    imageUrl: 'https://cdn.test/hero.jpg',
    imageWidth: 800,
    imageHeight: 450,
    publishedAt: null,
    createdAt: '2026-01-01T00:00:00Z',
    subscriptionId: 2,
    source: 'Src',
    faviconUrl: null,
    isRead: false,
    isFavorite: false,
    isKept: false,
    isViewed: false,
    ...over,
  } as EntryDto;
}

describe('feedHeroImage', () => {
  it('returns the feed image when the body carries none', () => {
    expect(feedHeroImage(entry(), '<p>Just words.</p>')).toEqual({
      url: 'https://cdn.test/hero.jpg',
      width: 800,
      height: 450,
    });
  });

  it('stands down when the body already shows the same picture', () => {
    expect(feedHeroImage(entry(), '<p>a</p><img src="https://cdn.test/hero.jpg">')).toBeNull();
  });

  it('stands down when the body shows the same picture under a size-variant URL', () => {
    expect(feedHeroImage(entry(), '<img src="https://cdn.test/hero.webp?width=960">')).toBeNull();
  });

  it('still leads when the body picture is a different one (the #505 case)', () => {
    expect(feedHeroImage(entry(), '<p>a</p><img src="https://cdn.test/inline.jpg">')).toEqual({
      url: 'https://cdn.test/hero.jpg',
      width: 800,
      height: 450,
    });
  });

  it('matches the same picture whatever the tag case or closing', () => {
    expect(feedHeroImage(entry(), '<IMG SRC="https://cdn.test/hero.jpg"/>')).toBeNull();
  });

  it('is not fooled by a word that merely starts with img', () => {
    expect(feedHeroImage(entry(), '<p>see the <imgur-embed></imgur-embed></p>')).not.toBeNull();
  });

  it('returns null without a persisted feed image', () => {
    expect(feedHeroImage(entry({ imageUrl: null }), '<p>words</p>')).toBeNull();
    expect(feedHeroImage(null, '<p>words</p>')).toBeNull();
  });

  it('passes unknown dimensions through rather than guessing', () => {
    expect(feedHeroImage(entry({ imageWidth: null, imageHeight: null }), '')).toEqual({
      url: 'https://cdn.test/hero.jpg',
      width: null,
      height: null,
    });
  });
});
