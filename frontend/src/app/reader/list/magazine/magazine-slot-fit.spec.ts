import { isMediaPost, postMedia, settle } from './magazine-slot-fit';
import { EntryDto, EntryMediumDto } from '../../models';
import { SHORT_PLAYER_URL } from '../../../../testing/short-marking-testing';
import { aLinkCard, anEntry } from '../../../../testing/entry-testing';

const card = aLinkCard();

const entry = (over: Partial<EntryDto> = {}): EntryDto =>
  anEntry({
    imageUrl: 'https://i/1.jpg',
    imageWidth: 900,
    imageHeight: 600,
    ...over,
  });

describe('settle', () => {
  it('gives a link slot to an entry without cards as the hero it can fill, never a link block', () => {
    expect(settle('link', entry(), 'thumb')).toBe('hero');
  });

  it('demotes a link slot down the hero ladder for an entry without a picture', () => {
    expect(settle('link', entry({ imageUrl: null, excerpt: 'Some copy' }), 'thumb')).toBe('kicker');
  });

  it('settles a share still being completed as a link block whatever its slot', () => {
    expect(settle('thumb', entry({ share: 'link', pending: true }), 'thumb')).toBe('link');
  });

  it('settles a titled link share with its card as a link block', () => {
    expect(settle('hero', entry({ share: 'link', linkCards: [card] }), 'thumb')).toBe('link');
  });

  it('keeps a link share whose card never came in the slot it fits', () => {
    expect(settle('hero', entry({ share: 'link' }), 'thumb')).toBe('hero');
  });
});

const image = (url: string): EntryMediumDto => ({ url, kind: 'image', width: 800, height: 600 });
const video = (url: string, poster: string): EntryMediumDto => ({
  url,
  kind: 'video',
  width: 1080,
  height: 1920,
  previewImageUrl: poster,
});

describe('postMedia', () => {
  it('drops the image that is only a video’s poster', () => {
    const clip = video('https://v/playlist.m3u8', 'https://v/thumb.jpg');
    const post = entry({
      titleDerived: true,
      share: 'media',
      media: [image('https://v/thumb.jpg'), clip],
    });

    expect(postMedia(post)).toEqual([clip]);
  });

  it('keeps at most four, in feed order', () => {
    const media = [1, 2, 3, 4, 5].map((index) => image(`https://i/${index}.jpg`));

    expect(postMedia(entry({ media })).map((medium) => medium.url)).toEqual([
      'https://i/1.jpg',
      'https://i/2.jpg',
      'https://i/3.jpg',
      'https://i/4.jpg',
    ]);
  });

  it('stands the lead image in when the feed declared no media', () => {
    expect(postMedia(entry())).toEqual([
      { url: 'https://i/1.jpg', kind: 'image', width: 900, height: 600 },
    ]);
  });

  it('is empty without media or a lead image', () => {
    expect(postMedia(entry({ imageUrl: null }))).toEqual([]);
  });
});

describe('isMediaPost', () => {
  it('is a media share with a picture', () => {
    expect(isMediaPost(entry({ share: 'media' }))).toBe(true);
  });

  it('is not an article, a link share or a media share without a picture', () => {
    expect(isMediaPost(entry())).toBe(false);
    expect(isMediaPost(entry({ titleDerived: true }))).toBe(false);
    expect(isMediaPost(entry({ share: 'link', linkCards: [card] }))).toBe(false);
    expect(isMediaPost(entry({ share: 'media', imageUrl: null }))).toBe(false);
  });

  it('takes a Short only by its share, like every other entry', () => {
    expect(isMediaPost(entry({ shortPlayerUrl: SHORT_PLAYER_URL }))).toBe(false);
    expect(isMediaPost(entry({ shortPlayerUrl: SHORT_PLAYER_URL, share: 'media' }))).toBe(true);
  });
});

describe('settle for a media post', () => {
  it('is a media block from any slot', () => {
    const post = entry({ titleDerived: true, share: 'media' });

    expect(settle('thumb', post, 'thumb')).toBe('media');
    expect(settle('compact', post, 'split')).toBe('media');
    expect(settle('hero', post, 'thumb')).toBe('media');
  });
});
