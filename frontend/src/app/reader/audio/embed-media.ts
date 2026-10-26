import { isAllowedEmbed } from '../media/media-embeds';
import { SOUNDCLOUD_PLAYER_URL } from '../media/soundcloud-widget-protocol';
import type { FrameKind } from './playback/playback-kind';

export interface EmbedMedia {
  readonly kind: FrameKind;
  /** The track's identity in the playlist and the url the deck picks the backend from. */
  readonly url: string;
  readonly posterUrl: string | null;
}

const VIDEO_ID = /^[A-Za-z0-9_-]{11}$/;
const WATCH_HOSTS = ['www.youtube.com', 'm.youtube.com', 'youtube.com'];
const EMBED_HOSTS = ['www.youtube-nocookie.com', 'www.youtube.com'];
const SOUNDCLOUD_TRACK = /^https:\/\/api\.soundcloud\.com\/tracks\/(\d+)$/;

export function youTubeVideoId(url: string): string | null {
  const parsed = URL.canParse(url) ? new URL(url) : null;
  if (parsed === null || parsed.hash === '#shorts') return null;
  const videoId = videoIdOf(parsed);
  return videoId !== null && VIDEO_ID.test(videoId) ? videoId : null;
}

export function isSoundCloudPlayerUrl(url: string): boolean {
  return url.startsWith(SOUNDCLOUD_PLAYER_URL) && isAllowedEmbed(url);
}

export function soundCloudTrackId(playerUrl: string): string | null {
  if (!playerUrl.startsWith(SOUNDCLOUD_PLAYER_URL) || !URL.canParse(playerUrl)) return null;
  const trackUrl = new URL(playerUrl).searchParams.get('url') ?? '';
  return SOUNDCLOUD_TRACK.exec(trackUrl)?.[1] ?? null;
}

/** The media a provider url plays, or null for a url this feature does not take. */
export function embedMediaOfUrl(url: string): EmbedMedia | null {
  const videoId = youTubeVideoId(url);
  if (videoId !== null) return youTubeEmbedMedia(videoId);
  if (isSoundCloudPlayerUrl(url)) return { kind: 'soundcloud', url, posterUrl: null };
  return null;
}

export function embedMediaOfFrame(frame: HTMLIFrameElement): EmbedMedia | null {
  return embedMediaOfUrl(frame.getAttribute('src') ?? '');
}

function youTubeEmbedMedia(videoId: string): EmbedMedia {
  return {
    kind: 'youtube',
    url: `https://www.youtube.com/watch?v=${videoId}`,
    posterUrl: `https://i.ytimg.com/vi/${videoId}/hqdefault.jpg`,
  };
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
