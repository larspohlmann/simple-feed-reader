import { YouTubeChapterPlayer } from './youtube-chapter-player';

const PLAYER_ORIGIN = 'https://www.youtube-nocookie.com';

function frameWithWindow(): { frame: HTMLIFrameElement; posted: unknown[] } {
  const frame = document.createElement('iframe');
  const posted: unknown[] = [];
  const contentWindow = { postMessage: (message: string) => posted.push(JSON.parse(message)) };
  Object.defineProperty(frame, 'contentWindow', { value: contentWindow });
  return { frame, posted };
}

/** jsdom accepts only a real Window as a MessageEvent source, so the fake one is set afterwards. */
function delivery(frame: HTMLIFrameElement, data: unknown, origin = PLAYER_ORIGIN): void {
  const event = new MessageEvent('message', { data: JSON.stringify(data), origin });
  Object.defineProperty(event, 'source', { value: frame.contentWindow });
  window.dispatchEvent(event);
}

describe('YouTubeChapterPlayer', () => {
  it('asks the player for its position once the frame loads', () => {
    const { frame, posted } = frameWithWindow();
    const player = new YouTubeChapterPlayer(frame);

    frame.dispatchEvent(new Event('load'));

    expect(posted).toEqual([{ event: 'listening', id: 1, channel: 'widget' }]);
    player.release();
  });

  it('seeks, then plays', () => {
    const { frame, posted } = frameWithWindow();
    const player = new YouTubeChapterPlayer(frame);

    player.playFrom(256);

    expect(posted).toEqual([
      { event: 'command', func: 'seekTo', args: [256, true], id: 1, channel: 'widget' },
      { event: 'command', func: 'playVideo', args: [], id: 1, channel: 'widget' },
    ]);
    player.release();
  });

  it('reports the position the player delivers', () => {
    const { frame } = frameWithWindow();
    const player = new YouTubeChapterPlayer(frame);
    const positions: (number | null)[] = [];
    player.watch((seconds) => positions.push(seconds));

    delivery(frame, { event: 'infoDelivery', info: { currentTime: 263.3 } });
    delivery(frame, { event: 'infoDelivery', info: { volume: 100 } });

    expect(positions).toEqual([263.3]);
    player.release();
  });

  it('ignores another origin and another frame', () => {
    const { frame } = frameWithWindow();
    const other = frameWithWindow().frame;
    const player = new YouTubeChapterPlayer(frame);
    const positions: (number | null)[] = [];
    player.watch((seconds) => positions.push(seconds));

    delivery(frame, { info: { currentTime: 5 } }, 'https://evil.test');
    delivery(other, { info: { currentTime: 5 } });

    expect(positions).toEqual([]);
    player.release();
  });

  it('stops listening once released', () => {
    const { frame } = frameWithWindow();
    const player = new YouTubeChapterPlayer(frame);
    const positions: (number | null)[] = [];
    player.watch((seconds) => positions.push(seconds));

    player.release();
    delivery(frame, { info: { currentTime: 5 } });

    expect(positions).toEqual([]);
  });
});
