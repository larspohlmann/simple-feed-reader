import { EMBED_FRAME_ATTRIBUTES } from '../../media/media-embeds';
import { FramePlayback } from './frame-playback';
import { PlaybackEvent } from './playback-element';

const ORIGIN = 'https://player.test';
const ALL_EVENTS: PlaybackEvent[] = [
  'progress',
  'timeupdate',
  'loadedmetadata',
  'durationchange',
  'play',
  'pause',
  'ended',
  'error',
];

class TestPlayback extends FramePlayback {
  readonly commands: string[] = [];
  readonly received: unknown[] = [];

  override reportTime(seconds: number): void {
    super.reportTime(seconds);
  }

  override reportDuration(seconds: number): void {
    super.reportDuration(seconds);
  }

  override reportLoaded(fraction: number): void {
    super.reportLoaded(fraction);
  }

  override reportPlaying(): void {
    super.reportPlaying();
  }

  override reportPaused(): void {
    super.reportPaused();
  }

  override reportEnded(): void {
    super.reportEnded();
  }

  override reportFailure(): void {
    super.reportFailure();
  }

  protected frameUrlFor(url: string): string {
    return `${ORIGIN}/embed?media=${encodeURIComponent(url)}`;
  }

  protected onFrameLoaded(): void {
    this.post({ event: 'listening' });
  }

  protected isReadyMessage(data: unknown): boolean {
    return data === 'ready';
  }

  protected readMessage(data: unknown): void {
    this.received.push(data);
  }

  protected start(seconds: number | null): void {
    this.commands.push(`start:${seconds}`);
  }

  protected stop(): void {
    this.commands.push('stop');
  }

  protected seekTo(seconds: number): void {
    this.commands.push(`seekTo:${seconds}`);
  }

  protected applyRate(rate: number): void {
    this.commands.push(`rate:${rate}`);
  }
}

interface Fixture {
  playback: TestPlayback;
  host: HTMLElement;
  events: PlaybackEvent[];
  frame: HTMLIFrameElement;
  posted: unknown[];
}

function frameOf(host: HTMLElement): HTMLIFrameElement {
  const frame = host.querySelector('iframe');
  if (!frame) throw new Error('no frame in the host');
  return frame;
}

/** Replaces the frame's window with one that records what is posted to it. */
function recordPosts(frame: HTMLIFrameElement): unknown[] {
  const posted: unknown[] = [];
  const contentWindow = { postMessage: (message: string) => posted.push(JSON.parse(message)) };
  Object.defineProperty(frame, 'contentWindow', { value: contentWindow, configurable: true });
  return posted;
}

/** jsdom accepts only a real Window as a MessageEvent source, so the fake one is set afterwards. */
function delivery(frame: HTMLIFrameElement, data: unknown, origin = ORIGIN): void {
  const event = new MessageEvent('message', { data, origin });
  Object.defineProperty(event, 'source', { value: frame.contentWindow });
  window.dispatchEvent(event);
}

function playbackWithSource(url = 'https://media.test/one'): Fixture {
  const host = document.createElement('div');
  document.body.appendChild(host);
  const playback = new TestPlayback(host, ORIGIN, 'A title');
  const events: PlaybackEvent[] = [];
  for (const type of ALL_EVENTS) playback.addEventListener(type, () => events.push(type));
  playback.src = url;
  const frame = frameOf(host);
  const posted = recordPosts(frame);
  return { playback, host, events, frame, posted };
}

function loadedPlayback(url?: string): Fixture {
  const fixture = playbackWithSource(url);
  fixture.frame.dispatchEvent(new Event('load'));
  return fixture;
}

function readyPlayback(url?: string): Fixture {
  const fixture = loadedPlayback(url);
  delivery(fixture.frame, 'ready');
  return fixture;
}

describe('FramePlayback', () => {
  afterEach(() => {
    document.body.innerHTML = '';
  });

  describe('frame', () => {
    it('holds no frame until a source is given', () => {
      const host = document.createElement('div');
      const playback = new TestPlayback(host, ORIGIN, 'A title');

      expect(host.querySelector('iframe')).toBeNull();
      expect(playback.src).toBe('');
      playback.dispose();
    });

    it('builds the provider frame in the host from the source, keeping the source as given', () => {
      const { playback, frame } = playbackWithSource('https://media.test/one');

      expect(playback.src).toBe('https://media.test/one');
      expect(frame.getAttribute('src')).toBe(
        'https://player.test/embed?media=https%3A%2F%2Fmedia.test%2Fone',
      );
      expect(frame.getAttribute('title')).toBe('A title');
      expect(frame.getAttribute('allow')).toBe('autoplay');
      expect(frame.getAttribute('loading')).toBe('eager');
      for (const [name, value] of Object.entries(EMBED_FRAME_ATTRIBUTES)) {
        expect(frame.getAttribute(name)).toBe(value);
      }
      playback.dispose();
    });

    it('replaces the frame on a new source', () => {
      const { playback, host, frame } = readyPlayback('https://media.test/one');

      playback.src = 'https://media.test/two';

      expect(frame.isConnected).toBe(false);
      expect(host.querySelectorAll('iframe')).toHaveLength(1);
      expect(frameOf(host).getAttribute('src')).toContain('media.test%2Ftwo');
      playback.dispose();
    });

    it('posts nothing before the frame has loaded, then lets the provider speak', () => {
      const { playback, frame, posted } = playbackWithSource();

      expect(posted).toEqual([]);
      frame.dispatchEvent(new Event('load'));

      expect(posted).toEqual([{ event: 'listening' }]);
      playback.dispose();
    });

    it('starts over with a fresh frame at the same source on load()', () => {
      const { playback, host, frame } = readyPlayback('https://media.test/one');
      playback.reportFailure();

      playback.load();

      expect(frame.isConnected).toBe(false);
      expect(frameOf(host).getAttribute('src')).toContain('media.test%2Fone');
      expect(playback.src).toBe('https://media.test/one');
      expect(playback.error).toBeNull();
      playback.dispose();
    });
  });

  describe('messages', () => {
    it('hands every message from its own frame to the provider', () => {
      const { playback, frame } = loadedPlayback();

      delivery(frame, 'ready');
      delivery(frame, '{"info":1}');

      expect(playback.received).toEqual(['ready', '{"info":1}']);
      playback.dispose();
    });

    it('ignores another origin and another window', () => {
      const { playback, frame } = loadedPlayback();
      const other = document.createElement('iframe');
      recordPosts(other);

      delivery(frame, 'ready', 'https://evil.test');
      delivery(other, 'ready');

      expect(playback.received).toEqual([]);
      playback.dispose();
    });

    it('ignores messages once disposed', () => {
      const { playback, frame } = loadedPlayback();

      playback.dispose();
      delivery(frame, 'ready');

      expect(playback.received).toEqual([]);
    });
  });

  describe('play', () => {
    it('reports playing at once and starts when the provider is ready', async () => {
      const { playback, frame, events } = loadedPlayback();

      await playback.play();

      expect(events).toEqual(['play']);
      expect(playback.commands).toEqual([]);

      delivery(frame, 'ready');

      expect(playback.commands).toEqual(['rate:1', 'start:null']);
      playback.dispose();
    });

    it('starts at once when the provider is ready', async () => {
      const { playback, frame, events } = readyPlayback();

      await playback.play();

      expect(events).toEqual(['play']);
      expect(playback.commands).toEqual(['rate:1', 'start:null']);
      expect(frame.isConnected).toBe(true);
      playback.dispose();
    });

    it('becomes ready on the first ready message only', () => {
      const { playback, frame } = loadedPlayback();

      delivery(frame, 'ready');
      delivery(frame, 'ready');

      expect(playback.commands).toEqual(['rate:1']);
      playback.dispose();
    });

    it('does not emit play twice for the provider confirming playback', async () => {
      const { playback, events } = readyPlayback();

      await playback.play();
      playback.reportPlaying();

      expect(events).toEqual(['play']);
      playback.dispose();
    });

    it('emits play when the provider starts on its own', () => {
      const { playback, events } = readyPlayback();

      playback.reportPlaying();
      playback.reportPlaying();

      expect(events).toEqual(['play']);
      playback.dispose();
    });
  });

  describe('pause', () => {
    it('stops the provider once ready and emits pause when the provider confirms', async () => {
      const { playback, events } = readyPlayback();
      await playback.play();
      playback.reportPlaying();

      playback.pause();
      expect(playback.commands).toEqual(['rate:1', 'start:null', 'stop']);
      expect(events).toEqual(['play']);

      playback.reportPaused();
      playback.reportPaused();

      expect(events).toEqual(['play', 'pause']);
      playback.dispose();
    });

    it('cancels a play asked for before ready and emits pause itself', async () => {
      const { playback, frame, events } = loadedPlayback();
      await playback.play();

      playback.pause();
      expect(events).toEqual(['play', 'pause']);

      delivery(frame, 'ready');

      expect(playback.commands).toEqual(['rate:1']);
      playback.dispose();
    });

    it('emits nothing for a pause while already paused', () => {
      const { playback, events } = loadedPlayback();

      playback.pause();

      expect(events).toEqual([]);
      playback.dispose();
    });
  });

  describe('seeking', () => {
    it('hands a seek before the start to the provider as the start position', async () => {
      const { playback, frame } = loadedPlayback();

      playback.currentTime = 0;
      playback.currentTime = 256;
      await playback.play();
      delivery(frame, 'ready');

      expect(playback.commands).toEqual(['rate:1', 'start:256']);
      expect(playback.currentTime).toBe(256);
      playback.dispose();
    });

    it('seeks at once after the provider started playing', () => {
      const { playback } = readyPlayback();
      playback.reportPlaying();

      playback.currentTime = 30;

      expect(playback.commands).toEqual(['rate:1', 'seekTo:30']);
      expect(playback.currentTime).toBe(30);
      playback.dispose();
    });

    it('forgets a held start once the provider has started', async () => {
      const { playback } = readyPlayback();
      playback.currentTime = 12;
      await playback.play();
      playback.reportPlaying();
      playback.pause();
      playback.reportPaused();

      void playback.play();

      expect(playback.commands).toEqual(['rate:1', 'start:12', 'stop', 'start:null']);
      playback.dispose();
    });

    it('reads the time the provider reports and emits timeupdate', () => {
      const { playback, events } = readyPlayback();

      playback.reportTime(42.5);

      expect(playback.currentTime).toBe(42.5);
      expect(events).toEqual(['timeupdate']);
      playback.dispose();
    });
  });

  describe('duration', () => {
    it('knows no duration and no metadata until the provider reports it', () => {
      const { playback } = readyPlayback();

      expect(playback.duration).toBeNaN();
      expect(playback.readyState).toBe(HTMLMediaElement.HAVE_NOTHING);
      playback.dispose();
    });

    it('emits the metadata events once per change and flips readyState', () => {
      const { playback, events } = readyPlayback();

      playback.reportDuration(600);
      playback.reportDuration(600);

      expect(playback.duration).toBe(600);
      expect(playback.readyState).toBe(HTMLMediaElement.HAVE_METADATA);
      expect(events).toEqual(['loadedmetadata', 'durationchange']);

      playback.reportDuration(601);

      expect(events).toEqual([
        'loadedmetadata',
        'durationchange',
        'loadedmetadata',
        'durationchange',
      ]);
      playback.dispose();
    });

    it('forgets the duration on a new source', () => {
      const { playback } = readyPlayback();
      playback.reportDuration(600);

      playback.src = 'https://media.test/two';

      expect(playback.duration).toBeNaN();
      expect(playback.readyState).toBe(HTMLMediaElement.HAVE_NOTHING);
      playback.dispose();
    });
  });

  describe('buffered', () => {
    it('has no range while the length is unknown or nothing is loaded', () => {
      const { playback } = readyPlayback();

      playback.reportLoaded(0.5);
      expect(playback.buffered.length).toBe(0);

      playback.reportDuration(600);
      playback.reportLoaded(0);
      expect(playback.buffered.length).toBe(0);
      playback.dispose();
    });

    it('spans from the start to the loaded fraction of the length and emits progress', () => {
      const { playback, events } = readyPlayback();
      playback.reportDuration(600);

      playback.reportLoaded(0.25);

      expect(playback.buffered.length).toBe(1);
      expect(playback.buffered.start(0)).toBe(0);
      expect(playback.buffered.end(0)).toBe(150);
      expect(events).toEqual(['loadedmetadata', 'durationchange', 'progress']);
      playback.dispose();
    });
  });

  describe('ending and failing', () => {
    it('emits pause, then ended', () => {
      const { playback, events } = readyPlayback();
      playback.reportPlaying();

      playback.reportEnded();

      expect(events).toEqual(['play', 'pause', 'ended']);
      playback.dispose();
    });

    it('reports a provider failure as an unsupported source', () => {
      const { playback, events } = readyPlayback();

      playback.reportFailure();

      const error = playback.error;
      expect(error?.code).toBe(error?.MEDIA_ERR_SRC_NOT_SUPPORTED);
      expect(events).toEqual(['error']);
      playback.dispose();
    });
  });

  describe('playback rate', () => {
    it('applies the rate once ready and re-applies it on every fresh frame', () => {
      const { playback, host } = loadedPlayback();
      playback.playbackRate = 1.5;
      expect(playback.commands).toEqual([]);

      delivery(frameOf(host), 'ready');
      expect(playback.commands).toEqual(['rate:1.5']);

      playback.playbackRate = 2;
      expect(playback.commands).toEqual(['rate:1.5', 'rate:2']);

      playback.src = 'https://media.test/two';
      const frame = frameOf(host);
      recordPosts(frame);
      frame.dispatchEvent(new Event('load'));
      delivery(frame, 'ready');

      expect(playback.playbackRate).toBe(2);
      expect(playback.commands).toEqual(['rate:1.5', 'rate:2', 'rate:2']);
      playback.dispose();
    });
  });

  describe('dispose', () => {
    it('removes the frame', () => {
      const { playback, host, frame } = readyPlayback();

      playback.dispose();

      expect(frame.isConnected).toBe(false);
      expect(host.querySelector('iframe')).toBeNull();
    });
  });
});
