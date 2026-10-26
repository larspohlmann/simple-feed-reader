import { frameWithWindow } from '../../../testing/frame-playback-testing';
import {
  finiteNumberOf,
  LISTENING,
  playerCommand,
  playerInfoOf,
  playerMessageOf,
  postToPlayerFrame,
} from './youtube-player-protocol';

describe('playerCommand', () => {
  it('wraps a function call in the widget envelope', () => {
    expect(playerCommand('seekTo', [256, true])).toEqual({
      event: 'command',
      func: 'seekTo',
      args: [256, true],
      id: 1,
      channel: 'widget',
    });
  });
});

describe('postToPlayerFrame', () => {
  it('posts the message as JSON to the privacy-enhanced player origin', () => {
    const { frame, posted } = frameWithWindow();

    postToPlayerFrame(frame, LISTENING);

    expect(posted).toEqual([
      {
        message: { event: 'listening', id: 1, channel: 'widget' },
        origin: 'https://www.youtube-nocookie.com',
      },
    ]);
  });

  it('survives a frame that has no window yet', () => {
    expect(() => postToPlayerFrame(document.createElement('iframe'), LISTENING)).not.toThrow();
  });
});

describe('playerMessageOf', () => {
  it('reads a JSON object the player posts', () => {
    expect(playerMessageOf('{"event":"onReady","info":{}}')).toEqual({
      event: 'onReady',
      info: {},
    });
  });

  it.each([
    ['a message that is no string', { event: 'onReady' }],
    ['text that is no JSON', 'not json'],
    ['a JSON null', 'null'],
    ['a JSON number', '42'],
    ['a JSON array', '[1]'],
  ])('yields null for %s', (_shape, data) => {
    expect(playerMessageOf(data)).toBeNull();
  });
});

describe('playerInfoOf', () => {
  it('reads the info a delivery carries', () => {
    expect(playerInfoOf({ event: 'infoDelivery', info: { currentTime: 3 } })).toEqual({
      currentTime: 3,
    });
  });

  it('has nothing to read without an info record', () => {
    expect(playerInfoOf({ event: 'onStateChange', info: 1 })).toEqual({});
    expect(playerInfoOf({ event: 'onReady' })).toEqual({});
    expect(playerInfoOf(null)).toEqual({});
  });
});

describe('finiteNumberOf', () => {
  it('passes a finite number through', () => {
    expect(finiteNumberOf(42.5)).toBe(42.5);
    expect(finiteNumberOf(0)).toBe(0);
  });

  it('yields null for anything else', () => {
    expect(finiteNumberOf(NaN)).toBeNull();
    expect(finiteNumberOf(Infinity)).toBeNull();
    expect(finiteNumberOf('42')).toBeNull();
    expect(finiteNumberOf(undefined)).toBeNull();
  });
});
