import { AudioTrack } from '../audio-player.service';
import { EntryDto } from '../models';
import { describedTrack, type TrackDetails } from './audio-track';
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
    title: entryDisplayTitle(entry),
    faviconUrl: entry.faviconUrl,
    imageUrl: media.posterUrl ?? entry.imageUrl,
    durationInSeconds: null,
    entry: audioTrackEntry(entry),
  };
  return details ? describedTrack(track, details) : track;
}
