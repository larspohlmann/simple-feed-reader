import { EntryAttachmentDto, EntryDto } from './models';
import { firstAudioAttachment } from './audio-attachment';

/** One image drawn from an entry's `media[]`, tied back to its entry (#916). */
export interface PictureTile {
  entryId: number;
  url: string;
  width?: number;
  height?: number;
}

/** One video drawn from an entry's `media[]`, with its poster and captions. */
export interface VideoTile {
  entryId: number;
  url: string;
  posterUrl: string | null;
  width?: number;
  height?: number;
  title: string;
  source: string;
}

/** An entry paired with the audio enclosure the Audios view plays (#915). */
export interface AudioItem {
  entry: EntryDto;
  attachment: EntryAttachmentDto;
}

/** Every image in the collection's `media[]`, first occurrence of each url. */
export function pictureTiles(entries: EntryDto[]): PictureTile[] {
  const tiles = entries.flatMap((entry) =>
    entry.media
      .filter((medium) => medium.kind === 'image')
      .map((medium) => ({
        entryId: entry.id,
        url: medium.url,
        width: medium.width,
        height: medium.height,
      })),
  );
  return dedupeByUrl(tiles);
}

/** Every video in the collection's `media[]`, first occurrence of each url. */
export function videoTiles(entries: EntryDto[]): VideoTile[] {
  const tiles = entries.flatMap((entry) =>
    entry.media
      .filter((medium) => medium.kind === 'video')
      .map((medium) => ({
        entryId: entry.id,
        url: medium.url,
        posterUrl: medium.previewImageUrl ?? null,
        width: medium.width,
        height: medium.height,
        title: entry.title,
        source: entry.source,
      })),
  );
  return dedupeByUrl(tiles);
}

/** Each entry that carries a playable audio enclosure, paired with the first. */
export function audioItems(entries: EntryDto[]): AudioItem[] {
  return entries.flatMap((entry) => {
    const attachment = firstAudioAttachment(entry.attachments);
    return attachment ? [{ entry, attachment }] : [];
  });
}

function dedupeByUrl<T extends { url: string }>(tiles: T[]): T[] {
  const seen = new Set<string>();
  return tiles.filter((tile) => {
    if (seen.has(tile.url)) return false;
    seen.add(tile.url);
    return true;
  });
}
