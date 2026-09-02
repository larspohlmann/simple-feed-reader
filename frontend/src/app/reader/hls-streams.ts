import type Hls from 'hls.js';

/**
 * Plays an HLS stream the backend emitted as a plain `<video src="….m3u8">`.
 *
 * `canPlayType` is no signal: Chrome answers "maybe" and then never plays the
 * playlist. So hls.js — loaded on demand, a lazy chunk outside the initial
 * bundle — takes every stream where Media Source Extensions exist, and only a
 * browser without them (iOS Safari) plays the playlist natively. `autoStartLoad`
 * is off and loading starts on the first play, so `preload="none"` keeps its
 * meaning.
 *
 * Runs in the reader's post-render pass beside upgradeMediaEmbeds. A re-render
 * replaces the whole body, so instances of detached videos are destroyed first.
 */
const PLAYLIST = /\.m3u8$/i;
const instances = new Map<HTMLVideoElement, Hls>();

export function attachHlsStreams(host: HTMLElement): void {
  destroyDetached();
  for (const video of Array.from(host.querySelectorAll('video'))) {
    const src = video.getAttribute('src') ?? '';
    if (!PLAYLIST.test(src) || instances.has(video)) continue;
    void attach(video, src).catch(() => undefined);
  }
}

async function attach(video: HTMLVideoElement, src: string): Promise<void> {
  const { default: HlsPlayer } = await import('hls.js');
  if (!HlsPlayer.isSupported() || !video.isConnected) return;
  const hls = new HlsPlayer({ autoStartLoad: false });
  hls.loadSource(src);
  hls.attachMedia(video);
  video.addEventListener('play', () => hls.startLoad(), { once: true });
  instances.set(video, hls);
}

function destroyDetached(): void {
  for (const [video, hls] of instances) {
    if (video.isConnected) continue;
    hls.destroy();
    instances.delete(video);
  }
}
