import { AudioTrack, AudioTrackEntry } from '../audio-player.service';
import { ChapterDto, EntryAttachmentDto, EntryDto } from '../models';
import { entryDisplayTitle } from '../entry/entry-text';

const AUDIO_EXTENSIONS = ['mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'wav', 'flac'];

/** The entry's first playable audio enclosure, or null. A feed-declared MIME
 *  type decides on its own (video/* is not audio, even for an .mp3 url); only
 *  when the feed omits the type do we fall back to the file extension. */
export function firstAudioAttachment(attachments: EntryAttachmentDto[]): EntryAttachmentDto | null {
  return attachments.find(isAudio) ?? null;
}

/** Everything the player needs to render and resume the enclosure, drawn from
 *  the entry (artwork, title fallback) and the attachment itself (#915). */
export function toAudioTrack(
  entry: EntryDto,
  attachment: EntryAttachmentDto,
  chapters: ChapterDto[] = [],
): AudioTrack {
  const track: AudioTrack = {
    url: attachment.url,
    title: attachment.title?.trim() || entryDisplayTitle(entry),
    faviconUrl: entry.faviconUrl,
    imageUrl: entry.imageUrl,
    durationInSeconds: attachment.durationInSeconds ?? null,
    entry: audioTrackEntry(entry),
  };
  return chapters.length > 0 ? { ...track, chapters } : track;
}

export function audioTrackEntry(entry: EntryDto): AudioTrackEntry {
  return {
    id: entry.id,
    title: entry.title,
    feedTitle: entry.poster?.name ?? entry.source,
    publishedAt: entry.publishedAt ?? entry.createdAt,
    excerpt: entry.excerpt,
  };
}

function isAudio(attachment: EntryAttachmentDto): boolean {
  if (attachment.mimeType) return attachment.mimeType.startsWith('audio/');
  const extension = attachment.url.split('?')[0].split('.').pop()?.toLowerCase();
  return extension !== undefined && AUDIO_EXTENSIONS.includes(extension);
}
