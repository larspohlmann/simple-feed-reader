import type Hls from 'hls.js';

/**
 * Plays an HLS stream the backend emitted as `<video preload="none" src="….m3u8">`.
 * `canPlayType` lies (Chrome says "maybe", then never plays it), so lazy-loaded
 * hls.js takes every browser with Media Source Extensions; only iOS Safari plays it
 * natively.
 *
 * hls.js attaches on the first play, never on render. Attaching a MediaSource up
 * front holds the video in NETWORK_LOADING with an empty buffer, and the native
 * controls then paint a buffering spinner over the poster forever (#950). Left
 * alone, the `preload="none"` element stays idle and shows only its poster; the
 * playlist src keeps the native play button working, and that first play swaps in
 * the MediaSource and resumes what the swap paused.
 *
 * The host that armed a stream owns it: it arms on render and releases on teardown.
 */
const PLAYLIST = /\.m3u8$/i;
const streamsByHost = new WeakMap<HTMLElement, Map<HTMLVideoElement, Hls>>();
const armed = new WeakSet<HTMLVideoElement>();

/** Arms the host's playlist videos for their first play, and releases the streams of the videos a re-render removed. */
export function armHlsStreams(host: HTMLElement): void {
  releaseWhere(host, (video) => !host.contains(video));
  for (const video of Array.from(host.querySelectorAll('video'))) {
    if (!isPlaylist(video) || armed.has(video)) continue;
    armed.add(video);
    const source = video.getAttribute('src') ?? '';
    video.addEventListener('play', () => void play(host, video, source).catch(() => undefined), {
      once: true,
    });
  }
}

export function releaseHlsStreams(host: HTMLElement): void {
  for (const hls of streamsByHost.get(host)?.values() ?? []) hls.destroy();
  streamsByHost.delete(host);
}

/** hls.js swaps such a video's source on first play, so the native attempt's error is not a failure. */
export function streamsThroughHls(video: HTMLVideoElement): boolean {
  return isPlaylist(video) || armed.has(video);
}

function isPlaylist(video: HTMLVideoElement): boolean {
  return PLAYLIST.test(video.getAttribute('src') ?? '');
}

async function play(host: HTMLElement, video: HTMLVideoElement, source: string): Promise<void> {
  const pause = pauseWatch(video);
  const { default: HlsPlayer } = await import('hls.js').finally(pause.stop);
  if (pause.happened() || !HlsPlayer.isSupported() || !video.isConnected) return;
  const hls = new HlsPlayer();
  streamsOf(host).set(video, hls);
  hls.loadSource(source);
  hls.attachMedia(video);
  void video.play();
}

/** Stopped before the source swap, which pauses the video itself. */
function pauseWatch(video: HTMLVideoElement): { happened: () => boolean; stop: () => void } {
  let paused = false;
  const notePause = (): void => {
    paused = true;
  };
  video.addEventListener('pause', notePause);
  return {
    happened: () => paused,
    stop: () => video.removeEventListener('pause', notePause),
  };
}

function streamsOf(host: HTMLElement): Map<HTMLVideoElement, Hls> {
  const streams = streamsByHost.get(host) ?? new Map<HTMLVideoElement, Hls>();
  streamsByHost.set(host, streams);
  return streams;
}

function releaseWhere(host: HTMLElement, shouldRelease: (video: HTMLVideoElement) => boolean) {
  const streams = streamsByHost.get(host);
  if (!streams) return;
  for (const [video, hls] of streams) {
    if (!shouldRelease(video)) continue;
    hls.destroy();
    streams.delete(video);
  }
}
