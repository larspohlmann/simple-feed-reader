import type { ChapterDto } from '../models';

/** The entry a track was queued from: the big player's details and its link back (#1442).
 *  Absent on tracks saved before it existed. */
export interface AudioTrackEntry {
  id: number;
  title: string;
  feedTitle: string;
  publishedAt: string;
  excerpt: string;
}

/** Everything the player needs to render and resume a track without another API
 *  call — built by the caller from the entry and its audio attachment (#915). */
export interface AudioTrack {
  url: string;
  title: string;
  faviconUrl: string | null;
  imageUrl: string | null;
  /** As the feed declared it, so the scrubber renders before metadata loads. */
  durationInSeconds: number | null;
  entry?: AudioTrackEntry;
  /** Ascending by start; absent on tracks saved before #1810 and on tracks queued where none were loaded. */
  chapters?: ChapterDto[];
}

/** What a track's own source tells of it after it was queued (#1824). */
export interface TrackDetails {
  title: string | null;
  durationSeconds: number | null;
  chapters: ChapterDto[];
}

export function chaptersOnly(chapters: ChapterDto[]): TrackDetails {
  return { title: null, durationSeconds: null, chapters };
}
