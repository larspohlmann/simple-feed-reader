import type { FramePlayback } from '../app/reader/audio/playback/frame-playback';
import type { PlaybackEvent } from '../app/reader/audio/playback/playback-element';

export const ALL_PLAYBACK_EVENTS: PlaybackEvent[] = [
  'progress',
  'timeupdate',
  'loadedmetadata',
  'durationchange',
  'play',
  'pause',
  'ended',
  'error',
];

export interface Posted {
  readonly message: unknown;
  readonly origin: string;
}

export interface PlaybackFixture<T extends FramePlayback> {
  readonly playback: T;
  readonly host: HTMLElement;
  readonly events: PlaybackEvent[];
  readonly frame: HTMLIFrameElement;
  readonly posted: Posted[];
  loaded(): PlaybackFixture<T>;
  ready(readyMessage: unknown): PlaybackFixture<T>;
}

/** Replaces the frame's window with one that records what is posted to it, and to which origin. */
export function recordPosts(frame: HTMLIFrameElement): Posted[] {
  const posted: Posted[] = [];
  const contentWindow = {
    postMessage: (message: string, origin: string) =>
      posted.push({ message: JSON.parse(message), origin }),
  };
  Object.defineProperty(frame, 'contentWindow', { value: contentWindow, configurable: true });
  return posted;
}

export function frameWithWindow(): { frame: HTMLIFrameElement; posted: Posted[] } {
  const frame = document.createElement('iframe');
  return { frame, posted: recordPosts(frame) };
}

export function messagesOf(posted: Posted[]): unknown[] {
  return posted.map(({ message }) => message);
}

/** jsdom accepts only a real Window as a MessageEvent source, so the fake one is set afterwards. */
export function deliverMessage(frame: HTMLIFrameElement, data: unknown, origin: string): void {
  const event = new MessageEvent('message', { data, origin });
  Object.defineProperty(event, 'source', { value: frame.contentWindow });
  window.dispatchEvent(event);
}

export function frameOf(host: HTMLElement): HTMLIFrameElement {
  const frame = host.querySelector('iframe');
  if (!frame) throw new Error('no frame in the host');
  return frame;
}

/** A playback in a host on the page, its frame built and recording posts; `loaded()` and `ready()` take it on. */
export function playbackFixture<T extends FramePlayback>(
  create: (host: HTMLElement) => T,
  url: string,
): PlaybackFixture<T> {
  const host = document.createElement('div');
  document.body.appendChild(host);
  const playback = create(host);
  const events: PlaybackEvent[] = [];
  for (const type of ALL_PLAYBACK_EVENTS) playback.addEventListener(type, () => events.push(type));
  playback.src = url;
  playback.load();
  const frame = frameOf(host);
  const posted = recordPosts(frame);
  const fixture: PlaybackFixture<T> = {
    playback,
    host,
    events,
    frame,
    posted,
    loaded: () => {
      frame.dispatchEvent(new Event('load'));
      return fixture;
    },
    ready: (readyMessage) => {
      deliverMessage(frame, readyMessage, originOf(frame));
      return fixture;
    },
  };
  return fixture;
}

function originOf(frame: HTMLIFrameElement): string {
  return new URL(frame.getAttribute('src') ?? '').origin;
}
