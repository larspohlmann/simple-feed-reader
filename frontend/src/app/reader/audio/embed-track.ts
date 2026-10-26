import { AudioTrack } from '../audio-player.service';
import { ChapterDto, EntryDto } from '../models';
import { entryDisplayTitle } from '../entry/entry-text';
import { audioTrackEntry } from './audio-attachment';
import { EmbedMedia } from './embed-media';

/** The track an embedded video or sound becomes in the player, mirroring `toAudioTrack`; its length is only known once it plays. */
export function toEmbedTrack(
  entry: EntryDto,
  media: EmbedMedia,
  chapters: ChapterDto[] = [],
): AudioTrack {
  const track: AudioTrack = {
    url: media.url,
    title: entryDisplayTitle(entry),
    faviconUrl: entry.faviconUrl,
    imageUrl: media.posterUrl ?? entry.imageUrl,
    durationInSeconds: null,
    entry: audioTrackEntry(entry),
  };
  return chapters.length > 0 ? { ...track, chapters } : track;
}
