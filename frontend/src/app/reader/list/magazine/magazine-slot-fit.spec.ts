import { shareKind, sharesMedia, sharedMedia } from './magazine-slot-fit';
import { EntryDto } from '../../models';
import { EntryMediumDto } from '../../entry-medium';
import { SHORT_PLAYER_URL } from '../../../../testing/short-marking-testing';
import { aLinkCard, aQuotedPost, anEntry } from '../../../../testing/entry-testing';

const card = aLinkCard();

const entry = (over: Partial<EntryDto> = {}): EntryDto =>
  anEntry({
    imageUrl: 'https://i/1.jpg',
    imageWidth: 900,
    imageHeight: 600,
    ...over,
  });

const image = (url: string): EntryMediumDto => ({ url, kind: 'image', width: 800, height: 600 });
const video = (url: string, poster: string): EntryMediumDto => ({
  url,
  kind: 'video',
  width: 1080,
  height: 1920,
  previewImageUrl: poster,
});

describe('sharedMedia', () => {
  it('drops the image that is only a video’s poster', () => {
    const clip = video('https://v/playlist.m3u8', 'https://v/thumb.jpg');
    const share = entry({
      titleDerived: true,
      share: 'media',
      media: [image('https://v/thumb.jpg'), clip],
    });

    expect(sharedMedia(share)).toEqual([clip]);
  });

  it('drops the image that is only a GIF’s poster', () => {
    const gif: EntryMediumDto = {
      ...video('https://g/clap.mp4', 'https://g/clap.jpg'),
      animated: true,
    };
    const share = entry({
      titleDerived: true,
      share: 'media',
      media: [image('https://g/clap.jpg'), gif],
    });

    expect(sharedMedia(share)).toEqual([gif]);
  });

  it('keeps at most four, in feed order', () => {
    const media = [1, 2, 3, 4, 5].map((index) => image(`https://i/${index}.jpg`));

    expect(sharedMedia(entry({ media })).map((medium) => medium.url)).toEqual([
      'https://i/1.jpg',
      'https://i/2.jpg',
      'https://i/3.jpg',
      'https://i/4.jpg',
    ]);
  });

  it('stands the lead image in when the feed declared no media', () => {
    expect(sharedMedia(entry())).toEqual([
      { url: 'https://i/1.jpg', kind: 'image', width: 900, height: 600 },
    ]);
  });

  it('is empty without media or a lead image', () => {
    expect(sharedMedia(entry({ imageUrl: null }))).toEqual([]);
  });
});

describe('sharesMedia', () => {
  it('is a media share with a picture', () => {
    expect(sharesMedia(entry({ share: 'media' }))).toBe(true);
  });

  it('is not an article, a link share or a media share without a picture', () => {
    expect(sharesMedia(entry())).toBe(false);
    expect(sharesMedia(entry({ titleDerived: true }))).toBe(false);
    expect(sharesMedia(entry({ share: 'link', linkCards: [card] }))).toBe(false);
    expect(sharesMedia(entry({ share: 'media', imageUrl: null }))).toBe(false);
  });

  it('takes a Short only by its share, like every other entry', () => {
    expect(sharesMedia(entry({ shortPlayerUrl: SHORT_PLAYER_URL }))).toBe(false);
    expect(sharesMedia(entry({ shortPlayerUrl: SHORT_PLAYER_URL, share: 'media' }))).toBe(true);
  });
});

describe('the quote share', () => {
  it('claims the quoting block when the entry carries its quoted post', () => {
    expect(shareKind(anEntry({ share: 'quote', quote: aQuotedPost() }))).toBe('quoting');
  });

  it('claims nothing when the quote is missing', () => {
    expect(shareKind(anEntry({ share: 'quote', quote: null }))).toBeNull();
  });

  it('leaves a link share with a stray quote to the link block', () => {
    expect(
      shareKind(anEntry({ share: 'link', quote: aQuotedPost(), linkCards: [aLinkCard()] })),
    ).toBe('link');
  });

  it('claims nothing for an article that merely carries a quote', () => {
    expect(shareKind(anEntry({ share: null, quote: aQuotedPost() }))).toBeNull();
  });
});
