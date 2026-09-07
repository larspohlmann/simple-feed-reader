import { EntryDto, EntryMediumDto } from './models';
import { audioItems, pictureTiles, videoTiles } from './media-view-projection';

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 'The entry headline',
  url: null,
  author: null,
  summary: null,
  contentHtml: null,
  imageUrl: null,
  imageWidth: null,
  imageHeight: null,
  media: [],
  attachments: [],
  publishedAt: null,
  createdAt: 'x',
  subscriptionId: 7,
  source: 'Src',
  faviconUrl: 'https://x.test/favicon.ico',
  isHidden: false,
  isFavorite: false,
  isKept: false,
  isViewed: false,
  ...over,
});

const image = (url: string, over: Partial<EntryMediumDto> = {}): EntryMediumDto => ({
  url,
  kind: 'image',
  ...over,
});

const video = (url: string, over: Partial<EntryMediumDto> = {}): EntryMediumDto => ({
  url,
  kind: 'video',
  ...over,
});

describe('pictureTiles', () => {
  it('keeps only image media and carries the owning entry', () => {
    const e = entry({
      id: 42,
      media: [
        image('https://x.test/a.jpg', { width: 800, height: 600 }),
        video('https://x.test/clip.mp4'),
      ],
    });

    expect(pictureTiles([e])).toEqual([
      { entryId: 42, url: 'https://x.test/a.jpg', width: 800, height: 600 },
    ]);
  });

  it('flattens across entries in order', () => {
    const first = entry({ id: 1, media: [image('https://x.test/1.jpg')] });
    const second = entry({ id: 2, media: [image('https://x.test/2.jpg')] });

    expect(pictureTiles([first, second]).map((t) => t.url)).toEqual([
      'https://x.test/1.jpg',
      'https://x.test/2.jpg',
    ]);
  });

  it('dedupes an identical url, keeping the first', () => {
    const first = entry({ id: 1, media: [image('https://x.test/same.jpg')] });
    const second = entry({ id: 2, media: [image('https://x.test/same.jpg')] });

    expect(pictureTiles([first, second])).toEqual([
      { entryId: 1, url: 'https://x.test/same.jpg', width: undefined, height: undefined },
    ]);
  });

  it('is empty when no entry declares an image', () => {
    expect(pictureTiles([entry(), entry({ media: [video('https://x.test/c.mp4')] })])).toEqual([]);
  });
});

describe('videoTiles', () => {
  it('keeps only video media with its poster and owning entry', () => {
    const e = entry({
      id: 9,
      title: 'A clip',
      source: 'Channel',
      media: [
        image('https://x.test/a.jpg'),
        video('https://x.test/v.mp4', {
          previewImageUrl: 'https://x.test/poster.jpg',
          width: 1920,
          height: 1080,
        }),
      ],
    });

    expect(videoTiles([e])).toEqual([
      {
        entryId: 9,
        url: 'https://x.test/v.mp4',
        posterUrl: 'https://x.test/poster.jpg',
        width: 1920,
        height: 1080,
        title: 'A clip',
        source: 'Channel',
      },
    ]);
  });

  it('carries a null poster when the feed declared none', () => {
    const e = entry({ id: 3, media: [video('https://x.test/v.mp4')] });

    expect(videoTiles([e])[0].posterUrl).toBeNull();
  });

  it('dedupes an identical video url', () => {
    const a = entry({ id: 1, media: [video('https://x.test/v.mp4')] });
    const b = entry({ id: 2, media: [video('https://x.test/v.mp4')] });

    expect(videoTiles([a, b])).toHaveLength(1);
  });
});

describe('audioItems', () => {
  it('pairs each entry with its first audio attachment', () => {
    const withAudio = entry({
      id: 5,
      attachments: [
        { url: 'https://x.test/clip.mp4', mimeType: 'video/mp4' },
        { url: 'https://x.test/ep.mp3', mimeType: 'audio/mpeg' },
      ],
    });

    const items = audioItems([withAudio]);

    expect(items).toHaveLength(1);
    expect(items[0].entry).toBe(withAudio);
    expect(items[0].attachment.url).toBe('https://x.test/ep.mp3');
  });

  it('drops entries with no audio attachment', () => {
    const noAudio = entry({ id: 6, attachments: [{ url: 'https://x.test/doc.pdf' }] });

    expect(audioItems([noAudio])).toEqual([]);
  });
});
