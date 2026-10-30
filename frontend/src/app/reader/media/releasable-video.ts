/** A list video whose media comes and goes with its distance from the screen (#1903). The element
 *  stays: iOS unlocks sound per element. */
export class ReleasableVideo {
  private resumeAt = 0;
  private pendingResume: AbortController | null = null;

  constructor(
    readonly element: HTMLVideoElement,
    private readonly source: string,
  ) {}

  attach(): void {
    if (this.element.hasAttribute('src')) return;
    this.element.setAttribute('src', this.source);
    if (this.resumeAt > 0) this.resumeOnceLoaded(this.resumeAt);
  }

  release(): void {
    if (!this.element.hasAttribute('src')) return;
    if (this.pendingResume) this.dropPendingResume();
    else this.resumeAt = this.element.ended ? 0 : this.element.currentTime;
    this.element.pause();
    this.element.removeAttribute('src');
    this.element.load();
  }

  private dropPendingResume(): void {
    this.pendingResume?.abort();
    this.pendingResume = null;
  }

  private resumeOnceLoaded(seconds: number): void {
    const pending = new AbortController();
    this.pendingResume = pending;
    this.element.addEventListener(
      'loadedmetadata',
      () => {
        this.element.currentTime = seconds;
        this.pendingResume = null;
      },
      { once: true, signal: pending.signal },
    );
  }
}
