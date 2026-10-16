import { isAllowedEmbed } from '../../../../media/media-embeds';

/** A Short's player, starting on load; null when there is none or it is not allow-listed. */
export function autoplayingShortPlayer(shortPlayerUrl: string | null): string | null {
  if (shortPlayerUrl === null || !isAllowedEmbed(shortPlayerUrl)) return null;
  const player = new URL(shortPlayerUrl);
  player.searchParams.set('autoplay', '1');
  return player.toString();
}

/** The player started without a tap: browsers only allow that muted, and iOS only inline. */
export function mutedShortPlayer(player: string): string {
  const muted = new URL(player);
  muted.searchParams.set('mute', '1');
  muted.searchParams.set('playsinline', '1');
  return muted.toString();
}
