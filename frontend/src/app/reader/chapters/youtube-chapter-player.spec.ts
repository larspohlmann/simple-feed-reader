import { PlaybackPosition } from './chapter-player';
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
    delivery(frame, { event: 'onReady' });

    player.playFrom(256);

    expect(posted).toEqual([
      { event: 'command', func: 'seekTo', args: [256, true], id: 1, channel: 'widget' },
      { event: 'command', func: 'playVideo', args: [], id: 1, channel: 'widget' },
    ]);
    player.release();
  });

  it('holds a chapter tapped before the player is ready, then plays it', () => {
    const { frame, posted } = frameWithWindow();
    const player = new YouTubeChapterPlayer(frame);

    player.playFrom(256);
    expect(posted).toEqual([]);

    delivery(frame, { event: 'initialDelivery', info: { currentTime: 0 } });

    expect(posted).toEqual([
      { event: 'command', func: 'seekTo', args: [256, true], id: 1, channel: 'widget' },
      { event: 'command', func: 'playVideo', args: [], id: 1, channel: 'widget' },
    ]);
    player.release();
  });

  it('plays only the last chapter tapped before the player is ready', () => {
    const { frame, posted } = frameWithWindow();
    const player = new YouTubeChapterPlayer(frame);

    player.playFrom(10);
    player.playFrom(256);
    delivery(frame, { event: 'infoDelivery', info: { currentTime: 0 } });
    delivery(frame, { event: 'infoDelivery', info: { currentTime: 1 } });

    expect(posted).toEqual([
      { event: 'command', func: 'seekTo', args: [256, true], id: 1, channel: 'widget' },
      { event: 'command', func: 'playVideo', args: [], id: 1, channel: 'widget' },
    ]);
    player.release();
  });

  it('drops a held chapter once released', () => {
    const { frame, posted } = frameWithWindow();
    const player = new YouTubeChapterPlayer(frame);

    player.playFrom(256);
    player.release();
    delivery(frame, { event: 'onReady' });

    expect(posted).toEqual([]);
  });

  it('reports the position the player delivers', () => {
    const { frame } = frameWithWindow();
    const player = new YouTubeChapterPlayer(frame);
    const positions: (PlaybackPosition | null)[] = [];
    player.watch((position) => positions.push(position));

    delivery(frame, { event: 'infoDelivery', info: { currentTime: 263.3 } });
    delivery(frame, { event: 'infoDelivery', info: { volume: 100 } });

    expect(positions).toEqual([{ seconds: 263.3, durationSeconds: null }]);
    player.release();
  });

  it('carries the last delivered duration on later positions', () => {
    const { frame } = frameWithWindow();
    const player = new YouTubeChapterPlayer(frame);
    const positions: (PlaybackPosition | null)[] = [];
    player.watch((position) => positions.push(position));

    delivery(frame, { event: 'initialDelivery', info: { currentTime: 0, duration: 600 } });
    delivery(frame, { event: 'infoDelivery', info: { currentTime: 12 } });

    expect(positions).toEqual([
      { seconds: 0, durationSeconds: 600 },
      { seconds: 12, durationSeconds: 600 },
    ]);
    player.release();
  });

  it('knows no duration while the player reports none', () => {
    const { frame } = frameWithWindow();
    const player = new YouTubeChapterPlayer(frame);
    const positions: (PlaybackPosition | null)[] = [];
    player.watch((position) => positions.push(position));

    delivery(frame, { event: 'infoDelivery', info: { currentTime: 30, duration: 0 } });

    expect(positions).toEqual([{ seconds: 30, durationSeconds: null }]);
    player.release();
  });

  it('ignores another origin and another frame', () => {
    const { frame } = frameWithWindow();
    const other = frameWithWindow().frame;
    const player = new YouTubeChapterPlayer(frame);
    const positions: (PlaybackPosition | null)[] = [];
    player.watch((position) => positions.push(position));

    delivery(frame, { info: { currentTime: 5 } }, 'https://evil.test');
    delivery(other, { info: { currentTime: 5 } });

    expect(positions).toEqual([]);
    player.release();
  });

  it('stops listening once released', () => {
    const { frame } = frameWithWindow();
    const player = new YouTubeChapterPlayer(frame);
    const positions: (PlaybackPosition | null)[] = [];
    player.watch((position) => positions.push(position));

    player.release();
    delivery(frame, { info: { currentTime: 5 } });

    expect(positions).toEqual([]);
  });
});
