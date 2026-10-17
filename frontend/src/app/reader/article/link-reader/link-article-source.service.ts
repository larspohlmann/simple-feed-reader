import { DestroyRef, Injectable, computed, inject, signal } from '@angular/core';
import { Observable, Subscription } from 'rxjs';
import { ReaderArticle, ReaderContent, ReaderFailure } from '../../models';
import { LinkCardRef } from '../../query/link-param';
import { ReaderContentService } from '../content/reader-content.service';
import { describeLoadError } from '../content/reader-load-error';

type LinkSourceState =
  | { status: 'loading' }
  | { status: 'ok'; article: ReaderArticle }
  | { status: 'failed'; failure: ReaderFailure | null; error: unknown };

/** The linked page a link reader shows, and its load lifecycle. A failure without a
 *  payload is a transport error or a timeout. */
@Injectable()
export class LinkArticleSource {
  private readonly reader = inject(ReaderContentService);
  private loadSub: Subscription | null = null;
  private readonly state = signal<LinkSourceState>({ status: 'loading' });

  readonly loading = computed(() => this.state().status === 'loading');
  readonly article = computed(() => {
    const state = this.state();
    return state.status === 'ok' ? state.article : null;
  });
  readonly failure = computed(() => {
    const state = this.state();
    return state.status === 'failed' ? state : null;
  });
  readonly errorDetail = computed(() => {
    const failed = this.failure();
    if (failed === null) return null;
    return failed.failure ? failed.failure.detail : describeLoadError(failed.error);
  });
  readonly leadFailed = signal(false);
  readonly lead = computed(() =>
    this.leadFailed() ? null : (this.failure()?.failure?.originalHero ?? null),
  );

  constructor() {
    inject(DestroyRef).onDestroy(() => this.loadSub?.unsubscribe());
  }

  open(ref: LinkCardRef): void {
    this.run(this.reader.loadLinkCard(ref));
  }

  /** Drop the page from the browser cache and refetch it. */
  reload(ref: LinkCardRef): void {
    this.run(this.reader.reloadLinkCard(ref));
  }

  private run(source: Observable<ReaderContent>): void {
    this.loadSub?.unsubscribe();
    this.state.set({ status: 'loading' });
    this.leadFailed.set(false);
    this.loadSub = source.subscribe({
      next: (content) =>
        this.state.set(
          content.status === 'ok'
            ? { status: 'ok', article: content }
            : { status: 'failed', failure: content, error: null },
        ),
      error: (error: unknown) => this.state.set({ status: 'failed', failure: null, error }),
    });
  }
}
