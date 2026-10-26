import { playbackKindOf } from './playback-kind';

const VIDEO_ID = 'dQw4w9WgXcQ';
const WIDGET_URL =
  'https://w.soundcloud.com/player/?url=https%3A%2F%2Fapi.soundcloud.com%2Ftracks%2F123456789';

describe('playbackKindOf', () => {
  it('plays a YouTube watch url through the YouTube backend', () => {
    expect(playbackKindOf(`https://www.youtube.com/watch?v=${VIDEO_ID}`)).toBe('youtube');
  });

  it('plays a youtu.be share link through the YouTube backend', () => {
    expect(playbackKindOf(`https://youtu.be/${VIDEO_ID}`)).toBe('youtube');
  });

  it('plays a nocookie embed through the YouTube backend', () => {
    expect(playbackKindOf(`https://www.youtube-nocookie.com/embed/${VIDEO_ID}`)).toBe('youtube');
  });

  it('plays a nocookie embed carrying the iframe API suffix through the YouTube backend', () => {
    const url = `https://www.youtube-nocookie.com/embed/${VIDEO_ID}?enablejsapi=1&origin=https%3A%2F%2Freader.test`;

    expect(playbackKindOf(url)).toBe('youtube');
  });

  it('does not take a Short, by design', () => {
    expect(playbackKindOf(`https://www.youtube-nocookie.com/embed/${VIDEO_ID}#shorts`)).toBe(
      'file',
    );
  });

  it('plays the SoundCloud widget url through the SoundCloud backend', () => {
    expect(playbackKindOf(WIDGET_URL)).toBe('soundcloud');
  });

  it('plays an audio file url as a file', () => {
    expect(playbackKindOf('https://x.test/episode.mp3')).toBe('file');
  });

  it('plays a stored url it cannot read as a file, so an old playlist still loads', () => {
    expect(playbackKindOf('')).toBe('file');
    expect(playbackKindOf('not a url')).toBe('file');
  });
});
