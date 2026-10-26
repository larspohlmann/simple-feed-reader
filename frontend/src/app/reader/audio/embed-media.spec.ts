import {
  SOUND_MEDIA,
  VIDEO_ID,
  VIDEO_MEDIA,
  WATCH_URL,
  WIDGET_URL,
} from '../../../testing/embed-media-testing';
import {
  embedMediaOfFrame,
  embedMediaOfUrl,
  isSoundCloudPlayerUrl,
  soundCloudTrackId,
  youTubeVideoId,
} from './embed-media';

const API_EMBED_URL = `https://www.youtube-nocookie.com/embed/${VIDEO_ID}?enablejsapi=1&origin=https%3A%2F%2Freader.test`;

function frameWithSource(source: string | null): HTMLIFrameElement {
  const frame = document.createElement('iframe');
  if (source !== null) frame.setAttribute('src', source);
  return frame;
}

describe('youTubeVideoId', () => {
  it.each([
    ['a watch url', WATCH_URL],
    ['a mobile watch url', `https://m.youtube.com/watch?v=${VIDEO_ID}`],
    [
      'a watch url with more query parameters',
      `https://www.youtube.com/watch?t=42&v=${VIDEO_ID}&list=PL1`,
    ],
    ['a youtu.be share link', `https://youtu.be/${VIDEO_ID}`],
    ['a youtu.be share link with a start time', `https://youtu.be/${VIDEO_ID}?t=42`],
    ['a nocookie embed', `https://www.youtube-nocookie.com/embed/${VIDEO_ID}`],
    ['a nocookie embed loaded with the iframe API', API_EMBED_URL],
    ['a youtube.com embed', `https://www.youtube.com/embed/${VIDEO_ID}`],
  ])('reads the id of %s', (_shape, url) => {
    expect(youTubeVideoId(url)).toBe(VIDEO_ID);
  });

  it.each([
    ['a Shorts embed', `https://www.youtube-nocookie.com/embed/${VIDEO_ID}#shorts`],
    ['a Shorts page', `https://www.youtube.com/shorts/${VIDEO_ID}`],
    ['a channel page', 'https://www.youtube.com/@channel'],
    ['a watch url without a video', 'https://www.youtube.com/watch?list=PL1'],
    ['an id of the wrong length', 'https://www.youtube.com/watch?v=abc'],
    ['an id with characters outside the alphabet', 'https://youtu.be/dQw4w9WgXc!'],
    ['a look-alike host', `https://www.youtube.com.evil.test/watch?v=${VIDEO_ID}`],
    ['another provider', 'https://player.vimeo.com/video/123456'],
    ['an audio file', 'https://x.test/episode.mp3'],
    ['an empty string', ''],
    ['text that is no url', 'not a url'],
  ])('yields null for %s', (_shape, url) => {
    expect(youTubeVideoId(url)).toBeNull();
  });
});

describe('isSoundCloudPlayerUrl', () => {
  it('accepts the allow-listed widget url, query and all', () => {
    expect(isSoundCloudPlayerUrl(WIDGET_URL)).toBe(true);
  });

  it('rejects a widget url the allow-list does not carry', () => {
    expect(isSoundCloudPlayerUrl(`${WIDGET_URL}&auto_play=true`)).toBe(false);
    expect(
      isSoundCloudPlayerUrl('https://w.soundcloud.com/player/?url=https%3A%2F%2Fevil.test'),
    ).toBe(false);
  });

  it('rejects a SoundCloud url that is not the widget', () => {
    expect(isSoundCloudPlayerUrl('https://soundcloud.com/artist/track')).toBe(false);
    expect(isSoundCloudPlayerUrl('https://api.soundcloud.com/tracks/123456789')).toBe(false);
  });

  it('rejects an empty string', () => {
    expect(isSoundCloudPlayerUrl('')).toBe(false);
  });
});

describe('embedMediaOfUrl', () => {
  it.each([
    ['a watch url', WATCH_URL],
    ['a youtu.be share link', `https://youtu.be/${VIDEO_ID}`],
    ['a nocookie embed', `https://www.youtube-nocookie.com/embed/${VIDEO_ID}`],
    ['a nocookie embed loaded with the iframe API', API_EMBED_URL],
  ])('reads %s as the video, under its canonical watch url with its poster', (_shape, url) => {
    expect(embedMediaOfUrl(url)).toEqual(VIDEO_MEDIA);
  });

  it('reads the SoundCloud widget url as the sound, kept as is and without a poster', () => {
    expect(embedMediaOfUrl(WIDGET_URL)).toEqual(SOUND_MEDIA);
  });

  it.each([
    ['a Short, by design', `https://www.youtube-nocookie.com/embed/${VIDEO_ID}#shorts`],
    ['a SoundCloud widget the allow-list does not carry', `${WIDGET_URL}&auto_play=true`],
    ['another provider', 'https://player.vimeo.com/video/123456'],
    ['an audio file', 'https://x.test/episode.mp3'],
    ['an empty string', ''],
    ['text that is no url', 'not a url'],
  ])('takes no media from %s', (_shape, url) => {
    expect(embedMediaOfUrl(url)).toBeNull();
  });
});

describe('embedMediaOfFrame', () => {
  it('reads the media of the frame source', () => {
    expect(embedMediaOfFrame(frameWithSource(API_EMBED_URL))).toEqual(VIDEO_MEDIA);
    expect(embedMediaOfFrame(frameWithSource(WIDGET_URL))).toEqual(SOUND_MEDIA);
  });

  it('leaves a frame without a source alone', () => {
    expect(embedMediaOfFrame(frameWithSource(null))).toBeNull();
  });
});

describe('soundCloudTrackId', () => {
  it('reads the track of the normalised widget url', () => {
    expect(soundCloudTrackId(WIDGET_URL)).toBe('123456789');
  });

  it('reads the track of a widget url with more parameters', () => {
    expect(soundCloudTrackId(`${WIDGET_URL}&color=%23ff5500&visual=true`)).toBe('123456789');
  });

  it.each([
    [
      'a playlist widget',
      'https://w.soundcloud.com/player/?url=https%3A%2F%2Fapi.soundcloud.com%2Fplaylists%2F42',
    ],
    ['a widget without a track', 'https://w.soundcloud.com/player/'],
    [
      'a look-alike track host',
      'https://w.soundcloud.com/player/?url=https%3A%2F%2Fapi.soundcloud.com.evil.test%2Ftracks%2F1',
    ],
    [
      'a non-SoundCloud url carrying a track',
      'https://x.test/player/?url=https%3A%2F%2Fapi.soundcloud.com%2Ftracks%2F123456789',
    ],
    ['text that is no url', 'not a url'],
  ])('yields null for %s', (_shape, url) => {
    expect(soundCloudTrackId(url)).toBeNull();
  });
});
