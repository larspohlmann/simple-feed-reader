import { toEmbedTrack } from './embed-track';
import { EmbedMedia } from './embed-media';
import { toAudioTrack } from './audio-attachment';
import { ChapterDto, EntryDto } from '../models';
import { anEntry } from '../../../testing/entry-testing';

const VIDEO_URL = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
const POSTER_URL = 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg';

const entry = (overrides: Partial<EntryDto> = {}): EntryDto =>
  anEntry({
    id: 42,
    title: 'The entry headline',
    imageUrl: 'https://x.test/cover.jpg',
    source: 'Fixture channel',
    faviconUrl: 'https://x.test/favicon.ico',
    publishedAt: '2026-09-01T08:00:00Z',
    excerpt: 'What this video is about.',
    ...overrides,
  });

const video = (overrides: Partial<EmbedMedia> = {}): EmbedMedia => ({
  kind: 'youtube',
  url: VIDEO_URL,
  posterUrl: POSTER_URL,
  ...overrides,
});

describe('toEmbedTrack', () => {
  it('builds the track the player queues for an embedded video', () => {
    expect(toEmbedTrack(entry(), video())).toEqual({
      url: VIDEO_URL,
      title: 'The entry headline',
      faviconUrl: 'https://x.test/favicon.ico',
      imageUrl: POSTER_URL,
      durationInSeconds: null,
      entry: {
        id: 42,
        title: 'The entry headline',
        feedTitle: 'Fixture channel',
        publishedAt: '2026-09-01T08:00:00Z',
        excerpt: 'What this video is about.',
      },
    });
  });

  it('shows the entry image when the media has no poster', () => {
    const track = toEmbedTrack(entry(), video({ kind: 'soundcloud', posterUrl: null }));

    expect(track.imageUrl).toBe('https://x.test/cover.jpg');
  });

  it('names the track after the source when the entry has no title', () => {
    expect(toEmbedTrack(entry({ title: '' }), video()).title).toBe('Fixture channel');
  });

  it('keeps the same entry block an enclosure track gets', () => {
    const embedTrack = toEmbedTrack(entry(), video());
    const enclosureTrack = toAudioTrack(entry(), { url: 'https://x.test/ep.mp3' });

    expect(embedTrack.entry).toEqual(enclosureTrack.entry);
  });

  it('has no chapters key without chapters, like an enclosure track', () => {
    expect(toEmbedTrack(entry(), video())).not.toHaveProperty('chapters');
  });

  it('carries the chapters the article loaded (#1810)', () => {
    const chapters: ChapterDto[] = [
      { startSeconds: 0, title: 'Intro' },
      { startSeconds: 90, title: 'Topic' },
    ];

    expect(toEmbedTrack(entry(), video(), chapters).chapters).toEqual(chapters);
  });
});
