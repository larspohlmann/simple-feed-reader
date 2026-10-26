/** Where playback is; `durationSeconds` is null until the player knows the media's length. */
export interface PlaybackPosition {
  readonly seconds: number;
  readonly durationSeconds: number | null;
}

/** What a chapter list drives: a player it can start at a chapter and follow (#1808). */
export interface ChapterPlayer {
  playFrom(seconds: number): void;
  watch(onPosition: (position: PlaybackPosition | null) => void): void;
  release(): void;
}
