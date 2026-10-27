import { toEmbedTrack } from './embed-track';
import { EmbedMedia } from './embed-media';
import { toAudioTrack } from './audio-attachment';
import { ChapterDto, EntryDto } from '../models';
import type { TrackDetails } from './audio-track';
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

  describe('with the embed details (#1824)', () => {
    const chapters: ChapterDto[] = [
      { startSeconds: 0, title: 'Intro' },
      { startSeconds: 90, title: 'Topic' },
    ];
    const details = (overrides: Partial<TrackDetails> = {}): TrackDetails => ({
      title: 'The video itself',
      durationSeconds: 4964,
      chapters,
      artworkUrl: null,
      ...overrides,
    });

    it("takes the embed's own title, length and chapters, keeping the post in its entry", () => {
      const track = toEmbedTrack(entry(), video(), details());

      expect(track.title).toBe('The video itself');
      expect(track.durationInSeconds).toBe(4964);
      expect(track.chapters).toEqual(chapters);
      expect(track.entry?.title).toBe('The entry headline');
    });

    it("shows the embed's own artwork over the poster and the entry image (#1831)", () => {
      const track = toEmbedTrack(
        entry(),
        video(),
        details({ artworkUrl: 'https://i1.sndcdn.com/artworks-a-t500x500.jpg' }),
      );

      expect(track.imageUrl).toBe('https://i1.sndcdn.com/artworks-a-t500x500.jpg');
    });

    it('falls back to the post title and an unknown length for what the details lack', () => {
      const track = toEmbedTrack(
        entry(),
        video(),
        details({ title: null, durationSeconds: null, chapters: [] }),
      );

      expect(track.title).toBe('The entry headline');
      expect(track.durationInSeconds).toBeNull();
      expect(track).not.toHaveProperty('chapters');
    });

    it('is the post-named track without details', () => {
      expect(toEmbedTrack(entry(), video(), null)).toEqual(toEmbedTrack(entry(), video()));
    });
  });
});
