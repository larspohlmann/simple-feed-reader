import { Observable, Subscription } from 'rxjs';
import { countsAreStale } from './sidebar-freshness';

/** A sidebar store's one load at a time, stamped for the counts poll's freshness window (#708): a new load
 *  supersedes the one out, and the poll asks again only once the window has passed with nothing out. */
export class StaleGatedLoad {
  private lastLoadedAt = 0;
  private inFlight: Subscription | null = null;

  /** A failed request leaves the last answer standing; the next stale tick asks again. */
  run<T>(
    request: Observable<T>,
    adopt: (response: T) => void,
    failed: (error: unknown) => void = () => undefined,
  ): void {
    this.lastLoadedAt = Date.now();
    this.inFlight?.unsubscribe();
    this.inFlight = null;
    const subscription = request.subscribe({
      next: (response) => {
        this.inFlight = null;
        adopt(response);
      },
      error: (error: unknown) => {
        this.inFlight = null;
        failed(error);
      },
    });
    // A response that came back synchronously has already left; parking its
    // closed subscription here would jam the in-flight guard for good.
    if (!subscription.closed) this.inFlight = subscription;
  }

  isStale(): boolean {
    return this.inFlight === null && countsAreStale(this.lastLoadedAt);
  }

  forget(): void {
    this.inFlight?.unsubscribe();
    this.inFlight = null;
    this.lastLoadedAt = 0;
  }
}
