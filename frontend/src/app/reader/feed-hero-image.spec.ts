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

  it('stands down when the body already shows a picture', () => {
    expect(feedHeroImage(entry(), '<p>a</p><img src="https://cdn.test/inline.jpg">')).toBeNull();
  });

  it('recognises an image tag whatever its case or closing', () => {
    expect(feedHeroImage(entry(), '<IMG SRC="https://x.test/a.jpg">')).toBeNull();
    expect(feedHeroImage(entry(), '<img/>')).toBeNull();
    expect(feedHeroImage(entry(), '<img>')).toBeNull();
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
