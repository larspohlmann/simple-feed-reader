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
 * Runs beside `upgradeMediaEmbeds`; a re-render destroys detached instances first.
 */
const PLAYLIST = /\.m3u8$/i;
const instances = new Map<HTMLVideoElement, Hls>();
const armed = new WeakSet<HTMLVideoElement>();

export function attachHlsStreams(host: HTMLElement): void {
  destroyDetached();
  for (const video of Array.from(host.querySelectorAll('video'))) {
    const src = video.getAttribute('src') ?? '';
    if (!PLAYLIST.test(src) || armed.has(video)) continue;
    armed.add(video);
    video.addEventListener('play', () => void play(video, src).catch(() => undefined), {
      once: true,
    });
  }
}

async function play(video: HTMLVideoElement, src: string): Promise<void> {
  const { default: HlsPlayer } = await import('hls.js');
  if (!HlsPlayer.isSupported() || !video.isConnected) return;
  const hls = new HlsPlayer();
  instances.set(video, hls);
  hls.loadSource(src);
  hls.attachMedia(video);
  void video.play();
}

function destroyDetached(): void {
  for (const [video, hls] of instances) {
    if (video.isConnected) continue;
    hls.destroy();
    instances.delete(video);
  }
}
