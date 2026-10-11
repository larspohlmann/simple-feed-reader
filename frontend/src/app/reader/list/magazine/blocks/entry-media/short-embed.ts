import { isAllowedEmbed } from '../../../../media-embeds';

/** A Short's player, starting on load; null when there is none or it is not allow-listed. */
export function autoplayingShortPlayer(shortPlayerUrl: string | null): string | null {
  if (shortPlayerUrl === null || !isAllowedEmbed(shortPlayerUrl)) return null;
  const player = new URL(shortPlayerUrl);
  player.searchParams.set('autoplay', '1');
  return player.toString();
}
