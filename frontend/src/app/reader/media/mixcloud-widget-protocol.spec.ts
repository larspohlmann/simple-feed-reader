import { frameWithWindow } from '../../../testing/frame-playback-testing';
import {
  GET_API,
  methodCall,
  MIXCLOUD_WIDGET_ORIGIN,
  mixcloudEventOf,
  mixcloudMessageOf,
  postToMixcloudFrame,
} from './mixcloud-widget-protocol';

describe('methodCall', () => {
  it('names the method and its arguments under a method id', () => {
    const call = methodCall('seek', [1800]);

    expect(call).toEqual({
      type: 'method',
      data: { methodId: expect.any(Number), methodName: 'seek', args: [1800] },
    });
  });

  it('calls without arguments by default', () => {
    expect(methodCall('play').data.args).toEqual([]);
  });

  it('gives every call the next id', () => {
    const first = methodCall('play');
    const second = methodCall('pause');

    expect(second.data.methodId).toBe(first.data.methodId + 1);
  });
});

describe('mixcloudMessageOf', () => {
  it('reads a player widget message', () => {
    expect(
      mixcloudMessageOf('{"mixcloud":"playerWidget","type":"api","data":{"methods":["play"]}}'),
    ).toEqual({ type: 'api', data: { methods: ['play'] } });
  });

  it('reads a message without data', () => {
    expect(mixcloudMessageOf('{"mixcloud":"playerWidget","type":"ready"}')).toEqual({
      type: 'ready',
      data: undefined,
    });
  });

  it.each([
    ['a message that is no string', { mixcloud: 'playerWidget', type: 'ready' }],
    ['text that is no JSON', '{not json'],
    ['a JSON null', 'null'],
    ['a JSON list', '[1]'],
    ['our own outgoing message, which has no mixcloud key', '{"type":"getApi"}'],
    ['another widget', '{"mixcloud":"footerWidget","type":"ready"}'],
    ['a type that is no string', '{"mixcloud":"playerWidget","type":1}'],
  ])('yields null for %s', (_shape, data) => {
    expect(mixcloudMessageOf(data)).toBeNull();
  });
});

describe('mixcloudEventOf', () => {
  it('reads an event and its arguments', () => {
    expect(
      mixcloudEventOf({ type: 'event', data: { eventName: 'progress', args: [42.5, 3600] } }),
    ).toEqual({ eventName: 'progress', args: [42.5, 3600] });
  });

  it('reads an event without arguments', () => {
    expect(mixcloudEventOf({ type: 'event', data: { eventName: 'play' } })).toEqual({
      eventName: 'play',
      args: [],
    });
  });

  it.each([
    ['another message type', { type: 'methodResponse', data: { methodId: 1, value: true } }],
    ['an event without a name', { type: 'event', data: { args: [] } }],
    ['arguments that are no list', { type: 'event', data: { eventName: 'progress', args: 3 } }],
    ['data that is no record', { type: 'event', data: 'play' }],
  ])('yields null for %s', (_shape, message) => {
    expect(mixcloudEventOf(message)).toBeNull();
  });
});

describe('postToMixcloudFrame', () => {
  it('posts the message as JSON to the widget origin', () => {
    const { frame, posted } = frameWithWindow();

    postToMixcloudFrame(frame, GET_API);

    expect(posted).toEqual([{ message: { type: 'getApi' }, origin: MIXCLOUD_WIDGET_ORIGIN }]);
  });

  it('survives a frame that has no window yet', () => {
    expect(() => postToMixcloudFrame(document.createElement('iframe'), GET_API)).not.toThrow();
  });
});
