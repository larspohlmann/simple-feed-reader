import { MIXCLOUD_WIDGET_URL } from '../../../../testing/embed-media-testing';
import {
  deliverMessage,
  messagesOf,
  playbackFixture,
  PlaybackFixture,
  Posted,
} from '../../../../testing/frame-playback-testing';
import { MIXCLOUD_WIDGET_ORIGIN } from '../../media/mixcloud-widget-protocol';
import { MixcloudPlayback } from './mixcloud-playback';

const API = {
  methods: ['play', 'pause', 'seek'],
  events: ['progress', 'play', 'pause', 'ended', 'error', 'buffering'],
};

type Fixture = PlaybackFixture<MixcloudPlayback>;

function widgetMessage(type: string, data?: unknown): string {
  return JSON.stringify({ mixcloud: 'playerWidget', type, data });
}

function fromWidget(frame: HTMLIFrameElement, type: string, data?: unknown): void {
  deliverMessage(frame, widgetMessage(type, data), MIXCLOUD_WIDGET_ORIGIN);
}

function widgetEvent(
  frame: HTMLIFrameElement,
  eventName: string,
  eventArguments: unknown[] = [],
): void {
  fromWidget(frame, 'event', { eventName, args: eventArguments });
}

/** What was posted: `getApi`, or a method by name and arguments, since method ids count across tests. */
function sent(posted: Posted[]): unknown[] {
  return messagesOf(posted).map((message) => {
    const { type, data } = message as {
      type: string;
      data?: { methodName: string; args: unknown[] };
    };
    return type === 'method' ? [data?.methodName, data?.args] : type;
  });
}

function playbackWithSource(): Fixture {
  return playbackFixture((host) => new MixcloudPlayback(host), MIXCLOUD_WIDGET_URL);
}

function loadedPlayback(): Fixture {
  return playbackWithSource().loaded();
}

function readyPlayback(): Fixture {
  return loadedPlayback().ready(widgetMessage('api', API));
}

async function startedPlayback(): Promise<Fixture> {
  const fixture = readyPlayback();
  await fixture.playback.play();
  return fixture;
}

describe('MixcloudPlayback', () => {
  afterEach(() => {
    document.body.innerHTML = '';
  });

  describe('frame', () => {
    it('loads the widget url as it is', () => {
      const { playback, frame } = playbackWithSource();

      expect(frame.getAttribute('src')).toBe(MIXCLOUD_WIDGET_URL);
      expect(frame.getAttribute('title')).toBe('Mixcloud');
      playback.dispose();
    });

    it('asks for the API once the frame has loaded', () => {
      const { playback, frame, posted } = playbackWithSource();

      expect(posted).toEqual([]);
      frame.dispatchEvent(new Event('load'));

      expect(posted).toEqual([{ message: { type: 'getApi' }, origin: MIXCLOUD_WIDGET_ORIGIN }]);
      playback.dispose();
    });

    it('asks again when the widget announces it is ready', () => {
      const { playback, frame, posted } = loadedPlayback();

      fromWidget(frame, 'ready');

      expect(sent(posted)).toEqual(['getApi', 'getApi']);
      playback.dispose();
    });
  });

  describe('play', () => {
    it('plays once the API arrives when asked before', async () => {
      const { playback, frame, events, posted } = loadedPlayback();

      await playback.play();
      expect(events).toEqual(['play']);
      expect(sent(posted)).toEqual(['getApi', 'getApi']);

      fromWidget(frame, 'api', API);

      expect(sent(posted)).toEqual(['getApi', 'getApi', ['play', []]]);
      playback.dispose();
    });

    it('plays at once when the API has arrived', async () => {
      const { playback, posted } = await startedPlayback();

      expect(sent(posted)).toEqual(['getApi', ['play', []]]);
      playback.dispose();
    });

    it('becomes ready on the API message only', async () => {
      const { playback, frame, posted } = loadedPlayback();
      await playback.play();

      widgetEvent(frame, 'progress', [1, 3600]);
      fromWidget(frame, 'methodResponse', { methodId: 1, value: true });

      expect(sent(posted)).toEqual(['getApi', 'getApi']);
      playback.dispose();
    });

    it('emits play when the widget starts on its own', () => {
      const { playback, frame, events } = readyPlayback();

      widgetEvent(frame, 'play');
      widgetEvent(frame, 'play');

      expect(events).toEqual(['play']);
      playback.dispose();
    });
  });

  describe('starting up', () => {
    it("ignores the widget's pause between a start and its first progress", async () => {
      const { playback, frame, events } = await startedPlayback();

      widgetEvent(frame, 'play');
      widgetEvent(frame, 'pause');
      widgetEvent(frame, 'play');

      expect(events).toEqual(['play']);
      playback.dispose();
    });

    it('reports a pause once the show has made progress', async () => {
      const { playback, frame, events } = await startedPlayback();
      widgetEvent(frame, 'play');
      widgetEvent(frame, 'progress', [0.5, 3600]);

      widgetEvent(frame, 'pause');

      expect(events).toEqual(['play', 'loadedmetadata', 'durationchange', 'timeupdate', 'pause']);
      playback.dispose();
    });

    it("reports the listener's own pause even before the first progress", async () => {
      const { playback, frame, events, posted } = await startedPlayback();

      playback.pause();
      widgetEvent(frame, 'pause');

      expect(sent(posted)).toEqual(['getApi', ['play', []], ['pause', []]]);
      expect(events).toEqual(['play', 'pause']);
      playback.dispose();
    });
  });

  describe('seeking', () => {
    it('seeks to a restored position on the first progress after the start, and only once', async () => {
      const { playback, frame, posted } = readyPlayback();
      playback.currentTime = 1800;
      await playback.play();
      expect(sent(posted)).toEqual(['getApi', ['play', []]]);

      widgetEvent(frame, 'play');
      widgetEvent(frame, 'progress', [0.4, 7093]);
      widgetEvent(frame, 'progress', [1800.2, 7093]);

      expect(sent(posted)).toEqual(['getApi', ['play', []], ['seek', [1800]]]);
      expect(playback.currentTime).toBe(1800.2);
      playback.dispose();
    });

    it('keeps the restored position while the seek is under way', async () => {
      const { playback, frame } = readyPlayback();
      playback.currentTime = 1800;
      await playback.play();

      widgetEvent(frame, 'progress', [0.4, 7093]);

      expect(playback.currentTime).toBe(1800);
      expect(playback.duration).toBe(7093);
      playback.dispose();
    });

    it('starts from the beginning without a seek', async () => {
      const { playback, frame, posted } = readyPlayback();
      playback.currentTime = 0;
      await playback.play();

      widgetEvent(frame, 'progress', [0.4, 7093]);

      expect(sent(posted)).toEqual(['getApi', ['play', []]]);
      playback.dispose();
    });

    it('seeks in seconds once the show plays', () => {
      const { playback, frame, posted } = readyPlayback();
      widgetEvent(frame, 'play');

      playback.currentTime = 300;

      expect(sent(posted)).toEqual(['getApi', ['seek', [300]]]);
      playback.dispose();
    });

    it("reads the position from progress, never from the seek's answer", () => {
      const { playback, frame, events } = readyPlayback();

      fromWidget(frame, 'methodResponse', { methodId: 1, value: true });
      expect(events).toEqual([]);

      widgetEvent(frame, 'progress', [42.5, 3600]);

      expect(playback.currentTime).toBe(42.5);
      expect(playback.duration).toBe(3600);
      expect(playback.readyState).toBe(HTMLMediaElement.HAVE_METADATA);
      expect(events).toEqual(['loadedmetadata', 'durationchange', 'timeupdate']);
      playback.dispose();
    });
  });

  describe('ending and failing', () => {
    it('emits pause, then ended when the show ends', () => {
      const { playback, frame, events } = readyPlayback();
      widgetEvent(frame, 'play');

      widgetEvent(frame, 'ended');

      expect(events).toEqual(['play', 'pause', 'ended']);
      playback.dispose();
    });

    it('marks the element dead on a widget error', () => {
      const { playback, frame, events } = readyPlayback();

      widgetEvent(frame, 'error');

      expect(playback.error?.code).toBe(4);
      expect(events).toEqual(['error']);
      playback.dispose();
    });
  });

  describe('a show that never starts', () => {
    beforeEach(() => jest.useFakeTimers());
    afterEach(() => jest.useRealTimers());

    it('fails when no progress follows a start within ten seconds', async () => {
      const { playback, events } = await startedPlayback();

      jest.advanceTimersByTime(9999);
      expect(events).toEqual(['play']);
      jest.advanceTimersByTime(1);

      expect(playback.error?.code).toBe(4);
      expect(events).toEqual(['play', 'error']);
      playback.dispose();
    });

    it('stays alive once the show makes progress', async () => {
      const { playback, frame, events } = await startedPlayback();
      widgetEvent(frame, 'progress', [0.5, 3600]);

      jest.advanceTimersByTime(20000);

      expect(playback.error).toBeNull();
      expect(events).not.toContain('error');
      playback.dispose();
    });

    it('ignores progress without a position', async () => {
      const { playback, frame } = await startedPlayback();
      widgetEvent(frame, 'progress', [null, 3600]);

      jest.advanceTimersByTime(10000);

      expect(playback.error?.code).toBe(4);
      playback.dispose();
    });

    it('stops waiting when the listener pauses', async () => {
      const { playback } = await startedPlayback();
      playback.pause();

      jest.advanceTimersByTime(20000);

      expect(playback.error).toBeNull();
      playback.dispose();
    });

    it('waits the full ten seconds again after a new start', async () => {
      const { playback } = await startedPlayback();
      jest.advanceTimersByTime(6000);
      playback.pause();
      await playback.play();

      jest.advanceTimersByTime(6000);
      expect(playback.error).toBeNull();
      jest.advanceTimersByTime(4000);

      expect(playback.error?.code).toBe(4);
      playback.dispose();
    });

    it('does not fail a new source for the old one', async () => {
      const { playback, events } = await startedPlayback();
      playback.src = MIXCLOUD_WIDGET_URL;

      jest.advanceTimersByTime(20000);

      expect(playback.error).toBeNull();
      expect(events).not.toContain('error');
      playback.dispose();
    });

    it('reports a widget error once, not again when the wait runs out', async () => {
      const { playback, frame, events } = await startedPlayback();
      widgetEvent(frame, 'error');

      jest.advanceTimersByTime(20000);

      expect(events).toEqual(['play', 'error']);
      playback.dispose();
    });

    it('does not fail once disposed', async () => {
      const { playback } = await startedPlayback();
      playback.dispose();

      jest.advanceTimersByTime(20000);

      expect(playback.error).toBeNull();
    });
  });

  describe('playback rate', () => {
    it('sends no rate, the widget has none', () => {
      const { playback, posted } = readyPlayback();

      playback.playbackRate = 2;

      expect(sent(posted)).toEqual(['getApi']);
      expect(playback.playbackRate).toBe(2);
      playback.dispose();
    });
  });

  describe('unwanted messages', () => {
    it('accepts nothing from the mixcloud.com site itself', () => {
      const { playback, frame, events, posted } = loadedPlayback();

      deliverMessage(frame, widgetMessage('api', API), 'https://www.mixcloud.com');
      deliverMessage(
        frame,
        widgetMessage('event', { eventName: 'error', args: [] }),
        'https://www.mixcloud.com',
      );

      expect(sent(posted)).toEqual(['getApi']);
      expect(events).toEqual([]);
      playback.dispose();
    });

    it('ignores what is not a widget message', () => {
      const { playback, frame, events } = readyPlayback();

      deliverMessage(frame, { mixcloud: 'playerWidget', type: 'event' }, MIXCLOUD_WIDGET_ORIGIN);
      deliverMessage(frame, '{not json', MIXCLOUD_WIDGET_ORIGIN);
      deliverMessage(frame, JSON.stringify({ type: 'event' }), MIXCLOUD_WIDGET_ORIGIN);
      widgetEvent(frame, 'progress', ['long', 'longer']);
      widgetEvent(frame, 'buffering');
      widgetEvent(frame, 'unknown');

      expect(events).toEqual([]);
      expect(playback.duration).toBeNaN();
      expect(playback.currentTime).toBe(0);
      playback.dispose();
    });
  });
});
