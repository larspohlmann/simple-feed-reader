/** What the inline Listen row plays and queues: an entry's enclosure or a clip in its body. */
export interface ListenOffer {
  duration(): string | null;
  queued(): boolean;
  play(): void;
  toggleQueued(): void;
}
