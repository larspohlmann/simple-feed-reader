import { isAllowedEmbed } from '../media/media-embeds';
import type { PlaybackKind } from './playback/playback-kind';

export interface EmbedMedia {
  readonly kind: Exclude<PlaybackKind, 'file'>;
  /** The track's identity in the playlist and the url the deck picks the backend from. */
  readonly url: string;
  readonly posterUrl: string | null;
}

const VIDEO_ID = /^[A-Za-z0-9_-]{11}$/;
const WATCH_HOSTS = ['www.youtube.com', 'm.youtube.com', 'youtube.com'];
const EMBED_HOSTS = ['www.youtube-nocookie.com', 'www.youtube.com'];
const SOUNDCLOUD_PLAYER = 'https://w.soundcloud.com/player/';
const PLAYER_API_SUFFIX = /\?enablejsapi=1.*$/;

export function youTubeVideoId(url: string): string | null {
  const parsed = parseUrl(url);
  if (parsed === null || parsed.hash === '#shorts') return null;
  const videoId = videoIdOf(parsed);
  return videoId !== null && VIDEO_ID.test(videoId) ? videoId : null;
}

export function youTubeWatchUrl(videoId: string): string {
  return `https://www.youtube.com/watch?v=${videoId}`;
}

export function youTubePosterUrl(videoId: string): string {
  return `https://i.ytimg.com/vi/${videoId}/hqdefault.jpg`;
}

export function youTubeEmbedMedia(videoId: string): EmbedMedia {
  return { kind: 'youtube', url: youTubeWatchUrl(videoId), posterUrl: youTubePosterUrl(videoId) };
}

export function isSoundCloudPlayerUrl(url: string): boolean {
  return url.startsWith(SOUNDCLOUD_PLAYER) && isAllowedEmbed(url);
}

/** The media a player frame in the article body shows, or null for a frame this feature does not take. */
export function embedMediaOfFrame(frame: HTMLIFrameElement): EmbedMedia | null {
  const url = (frame.getAttribute('src') ?? '').replace(PLAYER_API_SUFFIX, '');
  const videoId = youTubeVideoId(url);
  if (videoId !== null) return youTubeEmbedMedia(videoId);
  if (isSoundCloudPlayerUrl(url)) return { kind: 'soundcloud', url, posterUrl: null };
  return null;
}

function videoIdOf(url: URL): string | null {
  if (url.hostname === 'youtu.be') return url.pathname.slice(1);
  if (WATCH_HOSTS.includes(url.hostname) && url.pathname === '/watch')
    return url.searchParams.get('v');
  if (EMBED_HOSTS.includes(url.hostname)) return embeddedVideoIdOf(url.pathname);
  return null;
}

function embeddedVideoIdOf(pathname: string): string | null {
  const [root, segment, videoId, ...rest] = pathname.split('/');
  if (root !== '' || segment !== 'embed' || rest.length > 0) return null;
  return videoId ?? null;
}

function parseUrl(url: string): URL | null {
  try {
    return new URL(url);
  } catch {
    return null;
  }
}
