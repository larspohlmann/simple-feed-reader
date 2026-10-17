import { Injectable, computed, inject, signal } from '@angular/core';
import { Observable } from 'rxjs';
import { ReaderContent } from '../../models';
import { LinkCardRef } from '../../query/link-param';
import { ReaderContentService } from '../content/reader-content.service';
import { ReaderLoad } from '../content/reader-load';

/** The linked page a link reader shows, and its load lifecycle. */
@Injectable()
export class LinkArticleSource {
  private readonly reader = inject(ReaderContentService);
  private readonly load = new ReaderLoad();

  readonly loading = this.load.loading;
  readonly failed = this.load.failed;
  readonly article = this.load.article;
  readonly failure = this.load.failure;
  readonly errorDetail = this.load.errorDetail;
  readonly leadFailed = signal(false);
  readonly lead = computed(() =>
    this.leadFailed() ? null : (this.failure()?.originalHero ?? null),
  );

  open(ref: LinkCardRef): void {
    this.run(this.reader.load(ref));
  }

  /** Drop the page from the browser cache and refetch it. */
  reload(ref: LinkCardRef): void {
    this.run(this.reader.reload(ref));
  }

  private run(source: Observable<ReaderContent>): void {
    this.leadFailed.set(false);
    this.load.run(source);
  }
}
