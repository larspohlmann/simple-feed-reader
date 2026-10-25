/** What a chapter list drives: a player it can start at a chapter and follow (#1808). */
export interface ChapterPlayer {
  playFrom(seconds: number): void;
  watch(onPosition: (seconds: number | null) => void): void;
  release(): void;
}
