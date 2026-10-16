/** A medium the coordinator stops when another one plays. */
export interface PlayingMedium {
  readonly element: Element;
  stop(): void;
}

/** A video the coordinator can also start; `element` is what its visibility is measured on. */
export interface AutoplayPlayer extends PlayingMedium {
  start(withSound: boolean): void;
  /** Absent where sound cannot be unlocked, as in a cross-origin Short. */
  unlockSound?(): void;
}
