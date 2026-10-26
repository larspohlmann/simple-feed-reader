import { isSoundCloudPlayerUrl } from '../audio/embed-media';

const YOUTUBE_ORIGIN = 'https://www.youtube-nocookie.com';
const SOUNDCLOUD_ORIGIN = 'https://w.soundcloud.com';
const PAUSE_VIDEO = { event: 'command', func: 'pauseVideo', args: [], id: 1, channel: 'widget' };
const PAUSE_SOUND = { method: 'pause' };

/** Pauses a visible YouTube API player or SoundCloud widget; any other frame is left alone (#1815). */
export function pauseEmbedFrame(frame: HTMLIFrameElement): void {
  const source = frame.getAttribute('src') ?? '';
  if (isYouTubeApiPlayer(source)) return post(frame, PAUSE_VIDEO, YOUTUBE_ORIGIN);
  if (isSoundCloudPlayerUrl(source)) post(frame, PAUSE_SOUND, SOUNDCLOUD_ORIGIN);
}

function isYouTubeApiPlayer(source: string): boolean {
  const url = parseUrl(source);
  return url?.origin === YOUTUBE_ORIGIN && url.searchParams.get('enablejsapi') === '1';
}

function post(frame: HTMLIFrameElement, message: object, origin: string): void {
  frame.contentWindow?.postMessage(JSON.stringify(message), origin);
}

function parseUrl(source: string): URL | null {
  try {
    return new URL(source);
  } catch {
    return null;
  }
}
