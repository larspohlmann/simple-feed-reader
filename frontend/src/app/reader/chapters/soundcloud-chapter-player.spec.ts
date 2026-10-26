import {
  deliverMessage,
  frameWithWindow,
  messagesOf,
  Posted,
} from '../../../testing/frame-playback-testing';
import { SOUNDCLOUD_WIDGET_ORIGIN } from '../media/soundcloud-widget-protocol';
import { PlaybackPosition } from './chapter-player';
import { SoundCloudChapterPlayer } from './soundcloud-chapter-player';

const SUBSCRIPTION = [
  { method: 'addEventListener', value: 'playProgress' },
  { method: 'addEventListener', value: 'play' },
  { method: 'addEventListener', value: 'pause' },
  { method: 'getDuration' },
];
const PLAY = { method: 'play' };

function seekTo(milliseconds: number): object {
  return { method: 'seekTo', value: milliseconds };
}

function delivery(frame: HTMLIFrameElement, data: object, origin = SOUNDCLOUD_WIDGET_ORIGIN): void {
  deliverMessage(frame, JSON.stringify(data), origin);
}

function playerWithFrame(): {
  frame: HTMLIFrameElement;
  posted: Posted[];
  player: SoundCloudChapterPlayer;
} {
  const { frame, posted } = frameWithWindow();
  return { frame, posted, player: new SoundCloudChapterPlayer(frame) };
}

describe('SoundCloudChapterPlayer', () => {
  it('subscribes on construction and again when the frame loads', () => {
    const { frame, posted, player } = playerWithFrame();
    expect(messagesOf(posted)).toEqual(SUBSCRIPTION);

    frame.dispatchEvent(new Event('load'));

    expect(messagesOf(posted)).toEqual([...SUBSCRIPTION, ...SUBSCRIPTION]);
    player.release();
  });

  it('reports the position with the duration once known', () => {
    const { frame, player } = playerWithFrame();
    const positions: (PlaybackPosition | null)[] = [];
    player.watch((position) => positions.push(position));

    delivery(frame, { method: 'playProgress', value: { currentPosition: 12500 } });
    delivery(frame, { method: 'getDuration', value: 6443230 });
    delivery(frame, { method: 'playProgress', value: { currentPosition: 12500 } });

    expect(positions).toEqual([
      { seconds: 12.5, durationSeconds: null },
      { seconds: 12.5, durationSeconds: 6443.23 },
    ]);
    player.release();
  });

  it('ignores a message from another origin or another window', () => {
    const { frame, player } = playerWithFrame();
    const positions: (PlaybackPosition | null)[] = [];
    player.watch((position) => positions.push(position));
    const message = JSON.stringify({ method: 'playProgress', value: { currentPosition: 1000 } });

    delivery(
      frame,
      { method: 'playProgress', value: { currentPosition: 1000 } },
      'https://evil.test',
    );
    window.dispatchEvent(
      new MessageEvent('message', { data: message, origin: SOUNDCLOUD_WIDGET_ORIGIN }),
    );

    expect(positions).toEqual([]);
    player.release();
  });

  it('holds a chapter while paused and seeks once the sound plays', () => {
    const { frame, posted, player } = playerWithFrame();
    posted.length = 0;

    player.playFrom(600);
    expect(messagesOf(posted)).toEqual([PLAY]);

    delivery(frame, { method: 'play' });
    delivery(frame, { method: 'play' });

    expect(messagesOf(posted)).toEqual([PLAY, seekTo(600000)]);
    player.release();
  });

  it('seeks at once while playing', () => {
    const { frame, posted, player } = playerWithFrame();
    delivery(frame, { method: 'play' });
    posted.length = 0;

    player.playFrom(600);

    expect(messagesOf(posted)).toEqual([seekTo(600000)]);
    player.release();
  });

  it('plays a chapter tapped after a pause again', () => {
    const { frame, posted, player } = playerWithFrame();
    delivery(frame, { method: 'play' });
    delivery(frame, { method: 'pause' });
    posted.length = 0;

    player.playFrom(60);

    expect(messagesOf(posted)).toEqual([PLAY]);
    player.release();
  });

  it('learns from progress that the widget already plays, then seeks at once', () => {
    const { frame, posted, player } = playerWithFrame();
    delivery(frame, { method: 'playProgress', value: { currentPosition: 1000 } });
    posted.length = 0;

    player.playFrom(600);

    expect(messagesOf(posted)).toEqual([seekTo(600000)]);
    player.release();
  });

  it('sends a held seek on the first progress when no play event came', () => {
    const { frame, posted, player } = playerWithFrame();
    player.playFrom(600);
    posted.length = 0;

    delivery(frame, { method: 'playProgress', value: { currentPosition: 1000 } });

    expect(messagesOf(posted)).toEqual([seekTo(600000)]);
    player.release();
  });

  it('holds a chapter again after the frame reloads', () => {
    const { frame, posted, player } = playerWithFrame();
    delivery(frame, { method: 'play' });
    frame.dispatchEvent(new Event('load'));
    posted.length = 0;

    player.playFrom(600);

    expect(messagesOf(posted)).toEqual([PLAY]);
    player.release();
  });

  it('keeps no duration of 0', () => {
    const { frame, player } = playerWithFrame();
    const positions: (PlaybackPosition | null)[] = [];
    player.watch((position) => positions.push(position));

    delivery(frame, { method: 'getDuration', value: 0 });
    delivery(frame, { method: 'playProgress', value: { currentPosition: 1000 } });

    expect(positions).toEqual([{ seconds: 1, durationSeconds: null }]);
    player.release();
  });

  it('reports nothing and seeks nothing once released', () => {
    const { frame, posted, player } = playerWithFrame();
    const positions: (PlaybackPosition | null)[] = [];
    player.watch((position) => positions.push(position));
    player.playFrom(600);
    posted.length = 0;

    player.release();
    delivery(frame, { method: 'playProgress', value: { currentPosition: 1000 } });
    delivery(frame, { method: 'play' });
    frame.dispatchEvent(new Event('load'));

    expect(positions).toEqual([]);
    expect(posted).toEqual([]);
  });
});
