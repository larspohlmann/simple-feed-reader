import { AudioTrack } from '../audio-player.service';
import { EntryDto } from '../models';
import type { TrackDetails } from './audio-track';
import { entryDisplayTitle } from '../entry/entry-text';
import { audioTrackEntry } from './audio-attachment';
import { EmbedMedia } from './embed-media';

/** The track an embedded video or sound becomes in the player, mirroring `toAudioTrack`; named and
 *  timed by the embed's own details when they arrived, else by the post until it plays. */
export function toEmbedTrack(
  entry: EntryDto,
  media: EmbedMedia,
  details: TrackDetails | null = null,
): AudioTrack {
  const track: AudioTrack = {
    url: media.url,
    title: details?.title ?? entryDisplayTitle(entry),
    faviconUrl: entry.faviconUrl,
    imageUrl: pictureOf(entry, media, details),
    durationInSeconds: details?.durationSeconds ?? null,
    entry: audioTrackEntry(entry),
  };
  const chapters = details?.chapters ?? [];
  return chapters.length > 0 ? { ...track, chapters } : track;
}

function pictureOf(
  entry: EntryDto,
  media: EmbedMedia,
  details: TrackDetails | null,
): string | null {
  return details?.artworkUrl ?? media.posterUrl ?? entry.imageUrl;
}
