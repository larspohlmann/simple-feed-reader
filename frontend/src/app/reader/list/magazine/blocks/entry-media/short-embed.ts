import { isAllowedEmbed } from '../../../../media-embeds';

const SHORT_URL =
  /^https?:\/\/(?:www\.|m\.)?youtube\.com\/shorts\/([A-Za-z0-9_-]{11})\/?(?:[?#]|$)/i;

/** The allow-listed player of a YouTube Short, starting on load; null for any other URL. */
export function shortPlayerUrl(entryUrl: string | null): string | null {
  const id = entryUrl?.match(SHORT_URL)?.[1];
  const url = id ? `https://www.youtube-nocookie.com/embed/${id}?autoplay=1#shorts` : null;
  return url && isAllowedEmbed(url) ? url : null;
}
