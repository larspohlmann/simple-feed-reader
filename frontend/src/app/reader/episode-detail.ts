/** One chapter of a video or an episode (#1808). */
export interface ChapterDto {
  startSeconds: number;
  title: string;
}

/** One speaker's turn in an episode transcript; `startSeconds` is null for an untimed (HTML) one (#1809). */
export interface TranscriptParagraphDto {
  startSeconds: number | null;
  speaker: string | null;
  text: string;
}

export interface TranscriptDto {
  language: string | null;
  paragraphs: TranscriptParagraphDto[];
}
