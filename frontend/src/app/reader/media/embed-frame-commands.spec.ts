import { pauseEmbedFrame } from './embed-frame-commands';

interface Posted {
  readonly message: unknown;
  readonly origin: string;
}

const WIDGET_URL =
  'https://w.soundcloud.com/player/?url=https%3A%2F%2Fapi.soundcloud.com%2Ftracks%2F123456789';

function frameWithWindow(source: string | null): { frame: HTMLIFrameElement; posted: Posted[] } {
  const frame = document.createElement('iframe');
  if (source !== null) frame.setAttribute('src', source);
  const posted: Posted[] = [];
  const contentWindow = {
    postMessage: (message: string, origin: string) =>
      posted.push({ message: JSON.parse(message), origin }),
  };
  Object.defineProperty(frame, 'contentWindow', { value: contentWindow });
  return { frame, posted };
}

describe('pauseEmbedFrame', () => {
  it('pauses a YouTube player loaded with the iframe API', () => {
    const { frame, posted } = frameWithWindow(
      'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?enablejsapi=1&origin=https%3A%2F%2Freader.test',
    );

    pauseEmbedFrame(frame);

    expect(posted).toEqual([
      {
        message: { event: 'command', func: 'pauseVideo', args: [], id: 1, channel: 'widget' },
        origin: 'https://www.youtube-nocookie.com',
      },
    ]);
  });

  it('pauses a SoundCloud widget', () => {
    const { frame, posted } = frameWithWindow(WIDGET_URL);

    pauseEmbedFrame(frame);

    expect(posted).toEqual([{ message: { method: 'pause' }, origin: 'https://w.soundcloud.com' }]);
  });

  it('leaves a YouTube player without the API alone, as it takes no command', () => {
    const { frame, posted } = frameWithWindow('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ');

    pauseEmbedFrame(frame);

    expect(posted).toEqual([]);
  });

  it.each([
    ['another provider', 'https://player.vimeo.com/video/123456?enablejsapi=1'],
    ['a frame without a source', null],
  ])('leaves %s alone', (_, source) => {
    const { frame, posted } = frameWithWindow(source);

    pauseEmbedFrame(frame);

    expect(posted).toEqual([]);
  });

  it('survives a frame that has no window yet', () => {
    const frame = document.createElement('iframe');
    frame.setAttribute('src', WIDGET_URL);

    expect(() => pauseEmbedFrame(frame)).not.toThrow();
  });
});
