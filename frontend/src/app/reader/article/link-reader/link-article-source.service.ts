import { Injectable, computed, effect, inject, signal, untracked } from '@angular/core';
import { Observable } from 'rxjs';
import { ReaderContent } from '../../models';
import { ReaderRouteState } from '../../shell/reader-route-state.service';
import { ReaderContentService } from '../content/reader-content.service';
import { ReaderLoad } from '../content/reader-load';

/** The page behind the link card the route opens; idle while none is open. */
@Injectable()
export class LinkArticleSource {
  private readonly reader = inject(ReaderContentService);
  private readonly routeState = inject(ReaderRouteState);
  private readonly load = new ReaderLoad();

  readonly card = this.routeState.openLinkCard;
  readonly loading = this.load.loading;
  readonly failed = this.load.failed;
  readonly article = this.load.article;
  readonly failure = this.load.failure;
  readonly errorDetail = this.load.errorDetail;
  readonly leadFailed = signal(false);
  readonly lead = computed(() => (this.leadFailed() ? null : (this.card()?.imageUrl ?? null)));
  readonly title = computed(() => this.card()?.title || this.article()?.title || '');

  constructor() {
    effect(() => {
      const target = this.routeState.linkTarget();
      untracked(() => (target === null ? this.load.idle() : this.run(this.reader.load(target))));
    });
  }

  reload(): void {
    const target = this.routeState.linkTarget();
    if (target !== null) this.run(this.reader.reload(target));
  }

  private run(source: Observable<ReaderContent>): void {
    this.leadFailed.set(false);
    this.load.run(source);
  }
}
