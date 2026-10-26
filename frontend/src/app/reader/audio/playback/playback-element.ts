export type PlaybackEvent =
  | 'progress'
  | 'timeupdate'
  | 'loadedmetadata'
  | 'durationchange'
  | 'play'
  | 'pause'
  | 'ended'
  | 'error';

/** The slice of HTMLMediaElement the player drives; HTMLAudioElement satisfies it as is. */
export interface PlaybackElement {
  src: string;
  preload: '' | 'none' | 'metadata' | 'auto';
  currentTime: number;
  readonly duration: number;
  readonly buffered: TimeRanges;
  readonly readyState: number;
  readonly error: MediaError | null;
  defaultPlaybackRate: number;
  playbackRate: number;
  play(): Promise<void>;
  pause(): void;
  load(): void;
  addEventListener(type: PlaybackEvent, listener: () => void): void;
}

/** One element the deck can hold, with the way to let go of it. */
export interface PlaybackSlot {
  readonly element: PlaybackElement;
  dispose(): void;
}
