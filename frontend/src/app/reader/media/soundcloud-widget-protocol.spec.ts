import { widgetFieldOf, widgetMessage, widgetMessageOf } from './soundcloud-widget-protocol';

describe('widgetMessage', () => {
  it('names the method alone when there is no value', () => {
    expect(widgetMessage('play')).toEqual({ method: 'play' });
  });

  it('carries a value, a falsy one too', () => {
    expect(widgetMessage('seekTo', 0)).toEqual({ method: 'seekTo', value: 0 });
  });
});

describe('widgetMessageOf', () => {
  it('reads a JSON message naming a method', () => {
    expect(widgetMessageOf('{"method":"playProgress","value":{"currentPosition":1}}')).toEqual({
      method: 'playProgress',
      value: { currentPosition: 1 },
    });
  });

  it.each([
    ['a message that is no string', { method: 'ready' }],
    ['text that is no JSON', '{not json'],
    ['a JSON null', 'null'],
    ['a JSON object without a method', '{"value":"ready"}'],
    ['a method that is no string', '{"method":1}'],
  ])('yields null for %s', (_shape, data) => {
    expect(widgetMessageOf(data)).toBeNull();
  });
});

describe('widgetFieldOf', () => {
  it('reads a field of a value record', () => {
    expect(widgetFieldOf({ loadedProgress: 0.25 }, 'loadedProgress')).toBe(0.25);
  });

  it('reads nothing from a value that is no record', () => {
    expect(widgetFieldOf(null, 'loadedProgress')).toBeUndefined();
    expect(widgetFieldOf(600000, 'loadedProgress')).toBeUndefined();
  });
});
