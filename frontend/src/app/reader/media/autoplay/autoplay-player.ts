/** A list video the coordinator can start and stop; `element` is what its visibility is measured on. */
export interface AutoplayPlayer {
  readonly element: Element;
  start(withSound: boolean): void;
  stop(): void;
  /** Absent where sound cannot be unlocked, as in a cross-origin Short. */
  unlockSound?(): void;
}
