import { DestroyRef, Injectable, Signal, computed, inject, signal } from '@angular/core';
import { Observable, Subscription, timeout } from 'rxjs';
import { EntryDto, ReaderArticle, ReaderContent, ReaderFailure } from '../../models';
import { EntryBodyService } from './entry-body.service';
import { ReaderContentService } from './reader-content.service';
import { describeLoadError } from './reader-load-error';
import { ReaderModeService } from './reader-mode.service';

/** Give up on a hung extraction and fall back to feed content (backend caps a
 *  fetch at ~20s; this is the client-side backstop for a stalled connection). */
const READER_LOAD_TIMEOUT_MS = 30_000;

/** Failures that say the page holds no article, which Retry cannot change. */
const NO_ARTICLE_REASONS: ReadonlySet<ReaderFailure['reason']> = new Set([
  'unextractable',
  'empty',
  'mismatch',
  'player_page',
]);

type SourceState =
  | { status: 'idle' | 'loading' }
  | { status: 'ok'; article: ReaderArticle }
  | { status: 'failed'; failure: ReaderFailure | null; error: unknown };

/** What the open article shows: the extracted reader article, or the feed's
 *  own body, and the load lifecycle between them. */
@Injectable()
export class ArticleSource {
  private readonly reader = inject(ReaderContentService);
  private readonly bodyService = inject(EntryBodyService);
  private readonly readerMode = inject(ReaderModeService);
  private entry: Signal<EntryDto | null> = signal(null);

  private loadSub: Subscription | null = null;
  private readonly state = signal<SourceState>({ status: 'idle' });

  readonly loading = computed(() => this.state().status === 'loading');
  readonly failed = computed(() => this.state().status === 'failed');
  readonly pageHoldsNoArticle = computed(() => {
    const state = this.state();
    return (
      state.status === 'failed' &&
      state.failure !== null &&
      NO_ARTICLE_REASONS.has(state.failure.reason)
    );
  });
  /** The backend's reason the reader fell back; null after a transport failure,
   *  which carries no payload. */
  readonly failureReason = computed<ReaderFailure['reason'] | null>(() => {
    const state = this.state();
    return state.status === 'failed' ? (state.failure?.reason ?? null) : null;
  });
  /** The diagnostic detail behind the fallback note's "show error" disclosure: the
   *  server's own cause (a fetch's HTTP status or transport message), or the
   *  complete HTTP message of a transport failure in the browser. */
  readonly errorDetail = computed<string | null>(() => {
    const state = this.state();
    if (state.status !== 'failed') return null;
    return state.failure ? state.failure.detail : describeLoadError(state.error);
  });
  private readonly article = computed(() => {
    const state = this.state();
    return state.status === 'ok' ? state.article : null;
  });
  /** The reader body is the free preview of a paywalled article (#785). The
   *  original view shows the feed's own teaser, which needs no such note. */
  readonly paywalled = computed(
    () => this.readerMode.mode() === 'reader' && (this.article()?.paywalled ?? false),
  );
  readonly paywallUrl = computed(() => this.article()?.url || this.entry()?.url || null);
  /** Set when the original view's hero image fails to load, so a broken picture
   *  hides rather than leaving a torn placeholder. Reset on every entry change. */
  readonly heroFailed = signal(false);

  /** The payload the heroes come from. Null while loading, and after a
   *  transport error, where no payload arrived at all. */
  private readonly heroSource = computed<ReaderContent | null>(() => {
    const state = this.state();
    if (state.status === 'ok') return state.article;
    if (state.status === 'failed') return state.failure;
    return null;
  });

  /**
   * The picture that leads the ORIGINAL view. The reader view carries its own
   * lead inside contentHtml now (#681), so it needs no hero element here; only
   * the original view, which renders the raw feed body, still gets one.
   */
  readonly hero = computed(() => {
    const source = this.heroSource();
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

  readonly displayHtml = computed(() => {
    const entry = this.entry();
    if (!entry) return '';
    const article = this.article();
    if (this.readerMode.mode() === 'reader' && article) return article.contentHtml;
    // The summary renders at once; the body (once the store fetches it)
    // replaces it in place, and a failed fetch leaves the summary standing.
    const body = this.feedBody();
    return body?.status === 'ok' && body.html !== null ? body.html : (entry.summary ?? '');
  });

  constructor() {
    inject(DestroyRef).onDestroy(() => this.loadSub?.unsubscribe());
  }

  connect(entry: Signal<EntryDto | null>): void {
    this.entry = entry;
  }

  /** Start showing a newly opened entry (or none). */
  open(entry: EntryDto | null): void {
    this.heroFailed.set(false);
    if (!entry) {
      this.loadSub?.unsubscribe();
      this.state.set({ status: 'idle' });
      return;
    }
    if (!entry.url) {
      this.loadSub?.unsubscribe();
      this.state.set({ status: 'idle' });
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

  /** Subscribe to a content source (initial load or cache-busting reload),
   *  driving the loading → ok/failed lifecycle. Reader/original mode is
   *  untouched here — only a genuine entry change resets it. */
  private runLoad(source: Observable<ReaderContent>): void {
    this.loadSub?.unsubscribe();
    this.state.set({ status: 'loading' });
    this.loadSub = source.pipe(timeout({ first: READER_LOAD_TIMEOUT_MS })).subscribe({
      next: (content) => {
        if (content.status === 'ok') {
          this.state.set({ status: 'ok', article: content });
          this.readerMode.enableToggle();
        } else {
          this.state.set({ status: 'failed', failure: content, error: null });
          this.readerMode.setOriginalOnly();
        }
      },
      error: (error: unknown) => {
        // A timeout or a transport error leaves no payload, so this article
        // shows the feed's content with no hero. Keep the error so the reader
        // can reveal the complete HTTP message behind the fallback note.
        this.state.set({ status: 'failed', failure: null, error });
        this.readerMode.setOriginalOnly();
      },
    });
  }
}
