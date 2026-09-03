import type Hls from 'hls.js';

/**
 * Plays an HLS stream the backend emitted as `<video src="….m3u8">`. `canPlayType`
 * lies (Chrome says "maybe", then never plays it), so lazy-loaded hls.js takes
 * every browser with Media Source Extensions; only iOS Safari plays it natively.
 * `autoStartLoad` stays off until first play, so `preload="none"` keeps meaning.
 * Runs beside `upgradeMediaEmbeds`; a re-render destroys detached instances first.
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
