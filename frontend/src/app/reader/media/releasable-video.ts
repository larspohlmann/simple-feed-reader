/** A list video whose media comes and goes with its distance from the screen (#1903). The element
 *  stays: iOS unlocks sound per element. */
export class ReleasableVideo {
  private resumeAt = 0;
  private pendingResume: AbortController | null = null;
  private attached = false;

  constructor(
    readonly element: HTMLVideoElement,
    private readonly source: string,
  ) {}

  attach(): void {
    if (this.attached) return;
    this.attached = true;
    this.element.setAttribute('src', this.source);
    if (this.resumeAt > 0) this.resumeOnceLoaded(this.resumeAt);
  }

  release(): void {
    if (!this.attached) return;
    this.attached = false;
    if (this.pendingResume) this.dropPendingResume(this.pendingResume);
    else this.resumeAt = this.element.ended ? 0 : this.element.currentTime;
    this.element.pause();
    this.element.removeAttribute('src');
    this.element.load();
  }

  private dropPendingResume(pending: AbortController): void {
    pending.abort();
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
