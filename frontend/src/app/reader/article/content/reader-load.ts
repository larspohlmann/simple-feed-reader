import { DestroyRef, computed, inject, signal } from '@angular/core';
import { Observable, Subscription } from 'rxjs';
import { ReaderArticle, ReaderContent, ReaderFailure } from '../../models';
import { describeLoadError } from './reader-load-error';

type ReaderLoadState =
  | { status: 'idle' | 'loading' }
  | { status: 'ok'; article: ReaderArticle }
  | { status: 'failed'; failure: ReaderFailure | null; error: unknown };

export type ReaderLoadOutcome = 'ok' | 'failed';

/** One reader page's load lifecycle: idle → loading → ok/failed. A failure without a
 *  payload is a transport error or a timeout. Build it in an injection context. */
export class ReaderLoad {
  private subscription: Subscription | null = null;
  private readonly state = signal<ReaderLoadState>({ status: 'idle' });

  readonly loading = computed(() => this.state().status === 'loading');
  readonly failed = computed(() => this.state().status === 'failed');
  readonly article = computed(() => {
    const state = this.state();
    return state.status === 'ok' ? state.article : null;
  });
  readonly failure = computed(() => {
    const state = this.state();
    return state.status === 'failed' ? state.failure : null;
  });
  /** The payload either outcome delivered; null while loading and after a transport error. */
  readonly payload = computed<ReaderContent | null>(() => this.article() ?? this.failure());
  /** The diagnostic detail behind the "show error" disclosure: the server's own cause,
   *  or the complete HTTP message of a transport failure in the browser. */
  readonly errorDetail = computed<string | null>(() => {
    const state = this.state();
    if (state.status !== 'failed') return null;
    return state.failure ? state.failure.detail : describeLoadError(state.error);
  });

  constructor() {
    inject(DestroyRef).onDestroy(() => this.subscription?.unsubscribe());
  }

  run(
    source: Observable<ReaderContent>,
    settled: (outcome: ReaderLoadOutcome) => void = () => undefined,
  ): void {
    this.subscription?.unsubscribe();
    this.state.set({ status: 'loading' });
    this.subscription = source.subscribe({
      next: (content) => {
        this.state.set(
          content.status === 'ok'
            ? { status: 'ok', article: content }
            : { status: 'failed', failure: content, error: null },
        );
        settled(content.status);
      },
      error: (error: unknown) => {
        this.state.set({ status: 'failed', failure: null, error });
        settled('failed');
      },
    });
  }

  idle(): void {
    this.subscription?.unsubscribe();
    this.state.set({ status: 'idle' });
  }
}
