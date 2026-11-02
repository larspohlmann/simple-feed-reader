import { AudioTrack } from '../audio-player.service';
import { EntryDto } from '../models';
import { describedTrack, type AudioTrackEntry, type TrackDetails } from './audio-track';
import { entryDisplayTitle } from '../entry/entry-text';
import { audioTrackEntry } from './audio-attachment';
import { EmbedMedia } from './embed-media';

/** What an embed's track borrows from the page it plays in. */
export interface EmbedTrackOrigin {
  readonly title: string;
  readonly faviconUrl: string | null;
  readonly imageUrl: string | null;
  readonly entry: AudioTrackEntry;
}

export function embedTrackOrigin(entry: EntryDto): EmbedTrackOrigin {
  return {
    title: entryDisplayTitle(entry),
    faviconUrl: entry.faviconUrl,
    imageUrl: entry.imageUrl,
    entry: audioTrackEntry(entry),
  };
}

/** The track an embedded video or sound becomes in the player, mirroring `toAudioTrack`; named and
 *  timed by the embed's own details when they arrived, else by the post until it plays. */
export function toEmbedTrack(
  origin: EmbedTrackOrigin,
  media: EmbedMedia,
  details: TrackDetails | null = null,
): AudioTrack {
  const track: AudioTrack = {
    url: media.url,
    title: origin.title,
    faviconUrl: origin.faviconUrl,
    imageUrl: media.posterUrl ?? origin.imageUrl,
    durationInSeconds: null,
    entry: origin.entry,
  };
  return details ? describedTrack(track, details) : track;
}
