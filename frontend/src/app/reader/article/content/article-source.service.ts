import { Injectable, Signal, computed, inject, signal } from '@angular/core';
import { Observable } from 'rxjs';
import { EntryDto, ReaderContent, ReaderFailure } from '../../models';
import { EntryBodyService } from './entry-body.service';
import { ReaderContentService } from './reader-content.service';
import { ReaderLoad, ReaderLoadOutcome } from './reader-load';
import { ReaderModeService } from './reader-mode.service';
import { isPost } from '../../entry/entry-text';
import { articlePostMedia } from '../post-media/article-post-media';

const NO_ARTICLE_REASONS: ReadonlySet<ReaderFailure['reason']> = new Set([
  'unextractable',
  'empty',
  'mismatch',
  'player_page',
]);

function textOf(html: string | null): string {
  if (!html) return '';
  return (new DOMParser().parseFromString(html, 'text/html').body.textContent ?? '').trim();
}

/** What the open article shows: the extracted reader article, or the feed's
 *  own body, and the load lifecycle between them. */
@Injectable()
export class ArticleSource {
  private readonly reader = inject(ReaderContentService);
  private readonly bodyService = inject(EntryBodyService);
  private readonly readerMode = inject(ReaderModeService);
  private entry: Signal<EntryDto | null> = signal(null);

  private readonly load = new ReaderLoad();

  readonly loading = this.load.loading;
  readonly failed = this.load.failed;
  readonly errorDetail = this.load.errorDetail;
  private readonly article = this.load.article;
  readonly pageHoldsNoArticle = computed(() => {
    const failure = this.load.failure();
    return failure !== null && NO_ARTICLE_REASONS.has(failure.reason);
  });
  /** The backend's reason the reader fell back; null after a transport failure,
   *  which carries no payload. */
  readonly failureReason = computed(() => this.load.failure()?.reason ?? null);
  /** The reader body is the free preview of a paywalled article (#785). The
   *  original view shows the feed's own teaser, which needs no such note. */
  readonly paywalled = computed(
    () => this.readerMode.mode() === 'reader' && (this.article()?.paywalled ?? false),
  );
  readonly paywallUrl = computed(() => this.article()?.url || this.entry()?.url || null);
  /** Set when the original view's hero image fails to load, so a broken picture
   *  hides rather than leaving a torn placeholder. Reset on every entry change. */
  readonly heroFailed = signal(false);

  /**
   * The picture that leads the ORIGINAL view. The reader view carries its own
   * lead inside contentHtml now (#681), so it needs no hero element here; only
   * the original view, which renders the raw feed body, still gets one.
   */
  readonly hero = computed(() => {
    const source = this.load.payload();
    if (source === null || this.readerMode.mode() === 'reader') return null;
    const image = source.originalHero;
    return image === null || this.heroFailed() ? null : image;
  });

  /** The feed body for the open entry, fetched from the store on demand. Read
   *  unconditionally (not just in original mode) so the request starts the
   *  moment the entry opens, not only once the reader toggle falls back to it. */
  private readonly feedBody = computed(() => {
    const entry = this.entry();
    return entry ? this.bodyService.body(entry.id)() : null;
  });

  /** Whether the feed body failed to load and reader mode has no extracted
   *  article to show instead — the one case with nothing already on screen to
   *  fall back to beyond the summary. */
  readonly feedBodyFailed = computed(() => {
    if (this.readerMode.mode() === 'reader' && this.article()) return false;
    return this.feedBody()?.status === 'error';
  });

  private readonly feedBodyHtml = computed(() => {
    const body = this.feedBody();
    return body?.status === 'ok' ? body.html : null;
  });

  /** The open entry's chapters, which come with its feed body (#1808). */
  readonly chapters = computed(() => {
    const body = this.feedBody();
    return body?.status === 'ok' ? body.chapters : [];
  });

  /** The open episode's transcript, which comes with its feed body (#1809). */
  readonly transcript = computed(() => {
    const body = this.feedBody();
    return body?.status === 'ok' ? body.transcript : null;
  });

  /** With no show notes of its own, the transcript is what there is to read, so it opens. */
  readonly transcriptIsTheBody = computed(() => {
    const body = this.feedBody();
    return body?.status === 'ok' && textOf(body.html) === '';
  });

  readonly displayHtml = computed(() => {
    const entry = this.entry();
    if (!entry) return '';
    const article = this.article();
    if (this.readerMode.mode() === 'reader' && article) return article.contentHtml;
    // The summary renders at once; the body (once the store fetches it)
    // replaces it in place, and a failed fetch leaves the summary standing.
    return this.feedBodyHtml() ?? entry.summary ?? '';
  });

  /** The post's own media still to show under its body, once the body settled (#1656). */
  readonly postMedia = computed(() => {
    const entry = this.entry();
    const status = this.feedBody()?.status;
    if (!entry || status === undefined || status === 'loading') return [];
    return articlePostMedia(entry, this.feedBodyHtml() ?? '');
  });

  connect(entry: Signal<EntryDto | null>): void {
    this.entry = entry;
  }

  /** Start showing a newly opened entry (or none). */
  open(entry: EntryDto | null): void {
    this.heroFailed.set(false);
    if (!entry) {
      this.load.idle();
      return;
    }
    if (!entry.url || isPost(entry)) {
      this.load.idle();
      this.readerMode.setOriginalOnly();
      return;
    }
    this.runLoad(this.reader.load(entry.id));
  }

  /** Drop the article from the browser cache and refetch it. */
  reload(id: number): void {
    this.runLoad(this.reader.reload(id));
  }

  /** The inline error's retry action — refetches the entry's body. */
  retryBody(id: number): void {
    this.bodyService.retry(id);
  }

  private runLoad(source: Observable<ReaderContent>): void {
    this.load.run(source, (outcome) => this.settleMode(outcome));
  }

  private settleMode(outcome: ReaderLoadOutcome): void {
    if (outcome === 'ok') this.readerMode.enableToggle();
    else this.readerMode.setOriginalOnly();
  }
}
