import {
  embedMediaOfFrame,
  isSoundCloudPlayerUrl,
  youTubeEmbedMedia,
  youTubePosterUrl,
  youTubeVideoId,
  youTubeWatchUrl,
} from './embed-media';

const VIDEO_ID = 'dQw4w9WgXcQ';
const WIDGET_URL =
  'https://w.soundcloud.com/player/?url=https%3A%2F%2Fapi.soundcloud.com%2Ftracks%2F123456789';

function frameWithSource(source: string | null): HTMLIFrameElement {
  const frame = document.createElement('iframe');
  if (source !== null) frame.setAttribute('src', source);
  return frame;
}

describe('youTubeVideoId', () => {
  it.each([
    ['a watch url', `https://www.youtube.com/watch?v=${VIDEO_ID}`],
    ['a mobile watch url', `https://m.youtube.com/watch?v=${VIDEO_ID}`],
    [
      'a watch url with more query parameters',
      `https://www.youtube.com/watch?t=42&v=${VIDEO_ID}&list=PL1`,
    ],
    ['a youtu.be share link', `https://youtu.be/${VIDEO_ID}`],
    ['a youtu.be share link with a start time', `https://youtu.be/${VIDEO_ID}?t=42`],
    ['a nocookie embed', `https://www.youtube-nocookie.com/embed/${VIDEO_ID}`],
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

describe('youTubeWatchUrl', () => {
  it('is the canonical watch url of the video', () => {
    expect(youTubeWatchUrl(VIDEO_ID)).toBe(`https://www.youtube.com/watch?v=${VIDEO_ID}`);
  });
});

describe('youTubePosterUrl', () => {
  it('is the high-quality default thumbnail of the video', () => {
    expect(youTubePosterUrl(VIDEO_ID)).toBe(`https://i.ytimg.com/vi/${VIDEO_ID}/hqdefault.jpg`);
  });
});

describe('youTubeEmbedMedia', () => {
  it('builds the media of a video from its id', () => {
    expect(youTubeEmbedMedia(VIDEO_ID)).toEqual({
      kind: 'youtube',
      url: `https://www.youtube.com/watch?v=${VIDEO_ID}`,
      posterUrl: `https://i.ytimg.com/vi/${VIDEO_ID}/hqdefault.jpg`,
    });
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

describe('embedMediaOfFrame', () => {
  it('reads a YouTube frame loaded with the iframe API suffix as the video it shows', () => {
    const frame = frameWithSource(
      `https://www.youtube-nocookie.com/embed/${VIDEO_ID}?enablejsapi=1&origin=https%3A%2F%2Freader.test`,
    );

    expect(embedMediaOfFrame(frame)).toEqual({
      kind: 'youtube',
      url: `https://www.youtube.com/watch?v=${VIDEO_ID}`,
      posterUrl: `https://i.ytimg.com/vi/${VIDEO_ID}/hqdefault.jpg`,
    });
  });

  it('reads a YouTube frame loaded without the suffix the same way', () => {
    const frame = frameWithSource(`https://www.youtube-nocookie.com/embed/${VIDEO_ID}`);

    expect(embedMediaOfFrame(frame)?.url).toBe(`https://www.youtube.com/watch?v=${VIDEO_ID}`);
  });

  it('reads a SoundCloud widget as its allow-listed url, without a poster', () => {
    expect(embedMediaOfFrame(frameWithSource(WIDGET_URL))).toEqual({
      kind: 'soundcloud',
      url: WIDGET_URL,
      posterUrl: null,
    });
  });

  it('leaves a Short alone, by design', () => {
    const frame = frameWithSource(`https://www.youtube-nocookie.com/embed/${VIDEO_ID}#shorts`);

    expect(embedMediaOfFrame(frame)).toBeNull();
  });

  it('leaves a frame of another provider alone', () => {
    expect(embedMediaOfFrame(frameWithSource('https://player.vimeo.com/video/123456'))).toBeNull();
  });

  it('leaves a frame without a source alone', () => {
    expect(embedMediaOfFrame(frameWithSource(null))).toBeNull();
  });
});
