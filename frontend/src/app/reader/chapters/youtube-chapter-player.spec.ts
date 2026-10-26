import {
  deliverMessage,
  frameWithWindow,
  messagesOf,
  Posted,
} from '../../../testing/frame-playback-testing';
import { PlaybackPosition } from './chapter-player';
import { YouTubeChapterPlayer } from './youtube-chapter-player';

const PLAYER_ORIGIN = 'https://www.youtube-nocookie.com';
const LISTENING = { event: 'listening', id: 1, channel: 'widget' };
const PLAY_VIDEO = { event: 'command', func: 'playVideo', args: [], id: 1, channel: 'widget' };

function seekTo(seconds: number): object {
  return { event: 'command', func: 'seekTo', args: [seconds, true], id: 1, channel: 'widget' };
}

function delivery(frame: HTMLIFrameElement, data: unknown, origin = PLAYER_ORIGIN): void {
  deliverMessage(frame, JSON.stringify(data), origin);
}

function playerWithFrame(): {
  frame: HTMLIFrameElement;
  posted: Posted[];
  player: YouTubeChapterPlayer;
} {
  const { frame, posted } = frameWithWindow();
  return { frame, posted, player: new YouTubeChapterPlayer(frame) };
}

describe('YouTubeChapterPlayer', () => {
  it('asks the player for its position once the frame loads', () => {
    const { frame, posted, player } = playerWithFrame();

    frame.dispatchEvent(new Event('load'));

    expect(posted).toEqual([{ message: LISTENING, origin: PLAYER_ORIGIN }]);
    player.release();
  });

  it('seeks, then plays', () => {
    const { frame, posted, player } = playerWithFrame();
    delivery(frame, { event: 'onReady' });

    player.playFrom(256);

    expect(messagesOf(posted)).toEqual([seekTo(256), PLAY_VIDEO]);
    player.release();
  });

  it('holds a chapter tapped before the player is ready, then plays it', () => {
    const { frame, posted, player } = playerWithFrame();

    player.playFrom(256);
    expect(messagesOf(posted)).toEqual([LISTENING]);

    delivery(frame, { event: 'initialDelivery', info: { currentTime: 0 } });

    expect(messagesOf(posted)).toEqual([LISTENING, seekTo(256), PLAY_VIDEO]);

    player.playFrom(30);

    expect(messagesOf(posted).slice(3)).toEqual([seekTo(30), PLAY_VIDEO]);
    player.release();
  });

  it('plays only the last chapter tapped before the player is ready', () => {
    const { frame, posted, player } = playerWithFrame();

    player.playFrom(10);
    player.playFrom(256);
    delivery(frame, { event: 'infoDelivery', info: { currentTime: 0 } });
    delivery(frame, { event: 'infoDelivery', info: { currentTime: 1 } });

    expect(messagesOf(posted)).toEqual([LISTENING, LISTENING, seekTo(256), PLAY_VIDEO]);
    player.release();
  });

  it('drops a held chapter once released', () => {
    const { frame, posted, player } = playerWithFrame();

    player.playFrom(256);
    player.release();
    delivery(frame, { event: 'onReady' });

    expect(messagesOf(posted)).toEqual([LISTENING]);
  });

  it('reports the position the player delivers', () => {
    const { frame, player } = playerWithFrame();
    const positions: (PlaybackPosition | null)[] = [];
    player.watch((position) => positions.push(position));

    delivery(frame, { event: 'infoDelivery', info: { currentTime: 263.3 } });
    delivery(frame, { event: 'infoDelivery', info: { volume: 100 } });

    expect(positions).toEqual([{ seconds: 263.3, durationSeconds: null }]);
    player.release();
  });

  it('does not report the position of the message that plays a held chapter', () => {
    const { frame, player } = playerWithFrame();
    const positions: (PlaybackPosition | null)[] = [];
    player.watch((position) => positions.push(position));
    player.playFrom(256);

    delivery(frame, { event: 'initialDelivery', info: { currentTime: 0, duration: 600 } });
    expect(positions).toEqual([]);

    delivery(frame, { event: 'infoDelivery', info: { currentTime: 256 } });
    expect(positions).toEqual([{ seconds: 256, durationSeconds: 600 }]);
    player.release();
  });

  it('carries the last delivered duration on later positions', () => {
    const { frame, player } = playerWithFrame();
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
    const { frame, player } = playerWithFrame();
    const positions: (PlaybackPosition | null)[] = [];
    player.watch((position) => positions.push(position));

    delivery(frame, { event: 'infoDelivery', info: { currentTime: 30, duration: 0 } });

    expect(positions).toEqual([{ seconds: 30, durationSeconds: null }]);
    player.release();
  });

  it('ignores another origin and another frame', () => {
    const { frame, player } = playerWithFrame();
    const other = frameWithWindow().frame;
    const positions: (PlaybackPosition | null)[] = [];
    player.watch((position) => positions.push(position));

    delivery(frame, { info: { currentTime: 5 } }, 'https://evil.test');
    delivery(other, { info: { currentTime: 5 } });

    expect(positions).toEqual([]);
    player.release();
  });

  it('stops listening once released', () => {
    const { frame, player } = playerWithFrame();
    const positions: (PlaybackPosition | null)[] = [];
    player.watch((position) => positions.push(position));

    player.release();
    delivery(frame, { info: { currentTime: 5 } });

    expect(positions).toEqual([]);
  });
});
