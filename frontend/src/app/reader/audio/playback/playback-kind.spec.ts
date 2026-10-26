import { WATCH_URL, WIDGET_URL } from '../../../../testing/embed-media-testing';
import { playbackKindOf } from './playback-kind';

describe('playbackKindOf', () => {
  it('plays a YouTube url through the YouTube backend', () => {
    expect(playbackKindOf(WATCH_URL)).toBe('youtube');
  });

  it('plays the SoundCloud widget url through the SoundCloud backend', () => {
    expect(playbackKindOf(WIDGET_URL)).toBe('soundcloud');
  });

  it('plays any other url, readable or not, as a file, so an old playlist still loads', () => {
    expect(playbackKindOf('https://x.test/episode.mp3')).toBe('file');
    expect(playbackKindOf('')).toBe('file');
    expect(playbackKindOf('not a url')).toBe('file');
  });
});
