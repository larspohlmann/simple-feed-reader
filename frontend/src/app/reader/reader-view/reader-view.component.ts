// src/app/reader/reader-view/reader-view.component.ts
import {
  Component,
  DestroyRef,
  ElementRef,
  computed,
  effect,
  inject,
  input,
  output,
  signal,
  viewChild,
} from '@angular/core';
import { Subscription, timeout } from 'rxjs';
import { IconComponent } from '../../shared/icon/icon.component';
import { FaviconComponent } from '../../shared/favicon/favicon.component';
import { SourceTagsComponent } from '../source-tags/source-tags.component';
import { EntryDto, ReaderArticle, SubscriptionTagDto } from '../models';
import { ReaderContentService } from '../reader-content.service';
import { ReaderModeService } from '../reader-mode.service';
import { relativeTime } from '../format';

/** Give up on a hung extraction and fall back to feed content (backend caps a
 *  fetch at ~20s; this is the client-side backstop for a stalled connection). */
const READER_LOAD_TIMEOUT_MS = 30_000;

@Component({
  selector: 'app-reader-view',
  imports: [IconComponent, FaviconComponent, SourceTagsComponent],
  template: `
    @if (entry(); as e) {
      <div class="reader">
        @if (showToolbar()) {
          <div class="bar">
            <button class="close" type="button" aria-label="Back to list" (click)="close.emit()">
              <app-icon name="arrow_back" [size]="20" />
            </button>
            <div class="nav">
              @if (readerMode.canToggle()) {
                <button
                  class="mode"
                  type="button"
                  [attr.aria-pressed]="mode() === 'reader'"
                  [attr.aria-label]="
                    mode() === 'reader' ? 'Show original feed content' : 'Show reader view'
                  "
                  (click)="toggleMode()"
                >
                  <app-icon [name]="mode() === 'reader' ? 'article' : 'feed'" [size]="18" />
                  {{ mode() === 'reader' ? 'Reader' : 'Original' }}
                </button>
              }
              <button
                class="prev"
                type="button"
                aria-label="Previous"
                [disabled]="!hasPrev()"
                (click)="prev.emit()"
              >
                <app-icon name="chevron_left" [size]="20" />
              </button>
              <button
                class="next"
                type="button"
                aria-label="Next"
                [disabled]="!hasNext()"
                (click)="next.emit()"
              >
                <app-icon name="chevron_right" [size]="20" />
              </button>
            </div>
          </div>
        }
        <article>
          <div class="title-row">
            @if (!showToolbar()) {
              <button class="back" type="button" aria-label="Back to list" (click)="close.emit()">
                <app-icon name="arrow_back" [size]="20" />
              </button>
            }
            <h1 class="title">{{ e.title }}</h1>
          </div>
          <p class="meta">
            <app-favicon [url]="e.faviconUrl" [size]="16" />{{ e.source }}
            @if (e.author) {
              · {{ e.author }}
            }
            · {{ when(e) }}
            @if (e.url) {
              ·
              <a [href]="e.url" target="_blank" rel="noopener noreferrer"
                >Open original <app-icon name="open_in_new" [size]="14"
              /></a>
            }
          </p>
          <app-source-tags class="tags" [tags]="tags()" />
          <div class="actions">
            <button
              type="button"
              aria-label="Favorite"
              [class.on]="e.isFavorite"
              (click)="favorite.emit()"
            >
              <app-icon name="star" [size]="20" />
            </button>
            <button type="button" aria-label="Keep" [class.on]="e.isKept" (click)="keep.emit()">
              <app-icon name="bookmark" [size]="20" />
            </button>
            <button type="button" aria-label="Toggle read" (click)="read.emit()">
              <app-icon [name]="e.isRead ? 'mark_email_unread' : 'check'" [size]="20" />
            </button>
          </div>
          @if (loading()) {
            <div class="loading" role="status">Loading reader view…</div>
          } @else {
            @if (failed() && mode() === 'original') {
              <p class="reader-note">
                Couldn't load the full article — showing the feed's summary.
              </p>
            }
            @if (leadImage(); as img) {
              <img class="lead-image" [src]="img" alt="" />
            }
            <div #content class="content" [innerHTML]="displayHtml()"></div>
          }
        </article>
      </div>
    } @else {
      <div class="placeholder"><p>Select an article to read.</p></div>
    }
  `,
  styles: [
    `
      :host {
        display: block;
        height: 100%;
        overflow: auto;
      }
      /* A query container so the back button can react to the reading pane's
         own width (which differs from the window in split-pane mode). */
      .reader {
        position: relative;
        container-type: inline-size;
        container-name: reader;
      }
      .bar {
        position: sticky;
        top: 0;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: var(--space-2) var(--space-4);
        border-bottom: 1px solid var(--border);
        background: var(--surface-1);
      }
      .bar button,
      .actions button {
        background: none;
        border: none;
        color: var(--text-secondary);
        cursor: pointer;
        padding: var(--space-1);
      }
      .bar button:disabled {
        color: var(--text-muted);
        cursor: default;
      }
      article {
        max-width: 720px;
        margin: 0 auto;
        padding: var(--space-5) var(--space-4);
      }
      /* Narrow default: the back button stacks above the title (see the wide
         override below, which lifts it into the left gutter instead). */
      .title-row {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: var(--space-2);
        margin: 0 0 var(--space-2);
      }
      .back {
        flex: none;
        display: inline-flex;
        align-items: center;
        background: none;
        border: none;
        color: var(--text-secondary);
        cursor: pointer;
        padding: 2px;
      }
      .back:hover {
        color: var(--text-primary);
      }
      .title {
        font-size: var(--fs-xl);
        margin: 0;
        color: var(--text-primary);
      }
      /* Wide reading pane: hang the back button in the left gutter, out of flow,
         so it sits left of all content without pushing the title in. Keyed off
         the reader pane's own width, not the window's, so a narrow split pane
         also gets the stacked layout. */
      @container reader (min-width: 860px) {
        .back {
          position: absolute;
          left: var(--space-3);
          top: var(--space-5);
        }
      }
      .meta {
        font-size: var(--fs-sm);
        color: var(--text-muted);
        margin: 0 0 var(--space-3);
      }
      .meta a {
        color: var(--accent);
        text-decoration: none;
      }
      .tags {
        display: block;
        margin: 0 0 var(--space-3);
      }
      .actions {
        display: flex;
        gap: var(--space-4);
        padding: var(--space-2) 0 var(--space-4);
        border-bottom: 1px solid var(--border);
        margin-bottom: var(--space-4);
      }
      .actions button.on {
        color: var(--accent);
      }
      .lead-image {
        display: block;
        width: 100%;
        height: auto;
        border-radius: var(--radius);
        margin-bottom: var(--space-5);
      }
      /* Article typography. The body is [innerHTML]-injected, so its child
         elements carry no view-encapsulation attribute — the descendant rules
         must use ::ng-deep (kept scoped under .content). The sanitizer also
         strips default margins, so h2/p/li/blockquote need an explicit vertical
         rhythm or the article reads as one dense block. */
      .content {
        color: var(--text-primary);
        font-size: 16px;
        line-height: 1.75;
      }
      .content ::ng-deep p {
        margin: 0 0 var(--space-4);
      }
      .content ::ng-deep :is(h1, h2, h3, h4, h5, h6) {
        margin: var(--space-6) 0 var(--space-3);
        line-height: 1.3;
        font-weight: 650;
        color: var(--text-primary);
      }
      .content ::ng-deep h1 {
        font-size: 22px;
      }
      .content ::ng-deep h2 {
        font-size: 20px;
      }
      .content ::ng-deep h3 {
        font-size: 17px;
      }
      .content ::ng-deep :is(h4, h5, h6) {
        font-size: 16px;
      }
      .content ::ng-deep :is(ul, ol) {
        margin: 0 0 var(--space-4);
        padding-left: 1.5em;
      }
      .content ::ng-deep li {
        margin: var(--space-1) 0;
      }
      .content ::ng-deep li::marker {
        color: var(--text-muted);
      }
      .content ::ng-deep blockquote {
        margin: var(--space-5) 0;
        padding: var(--space-1) 0 var(--space-1) var(--space-4);
        border-left: 3px solid var(--border-strong);
        color: var(--text-secondary);
      }
      .content ::ng-deep figure {
        margin: var(--space-5) 0;
      }
      .content ::ng-deep figcaption {
        margin-top: var(--space-2);
        font-size: var(--fs-sm);
        color: var(--text-muted);
        text-align: center;
      }
      .content ::ng-deep :is(img, video, iframe) {
        max-width: 100%;
        height: auto;
        border-radius: var(--radius);
      }
      .content ::ng-deep :not(pre) > code {
        padding: 0.1em 0.35em;
        background: var(--surface-1);
        border-radius: var(--radius);
        font-size: 0.9em;
      }
      .content ::ng-deep pre {
        margin: 0 0 var(--space-4);
        padding: var(--space-3);
        background: var(--surface-1);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        overflow-x: auto;
        line-height: 1.5;
      }
      .content ::ng-deep pre code {
        padding: 0;
        background: none;
      }
      .content ::ng-deep hr {
        margin: var(--space-5) 0;
        border: 0;
        border-top: 1px solid var(--border);
      }
      .content ::ng-deep a {
        color: var(--accent);
      }
      .placeholder {
        display: grid;
        place-items: center;
        height: 100%;
        color: var(--text-muted);
      }
      .mode {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: var(--fs-sm);
      }
      .mode[aria-pressed='true'] {
        color: var(--accent);
      }
      .loading {
        padding: var(--space-5) var(--space-4);
        color: var(--text-muted);
      }
      .reader-note {
        font-size: var(--fs-sm);
        color: var(--text-muted);
        margin: 0 0 var(--space-3);
      }
    `,
  ],
})
export class ReaderViewComponent {
  readonly entry = input.required<EntryDto | null>();
  readonly tags = input<SubscriptionTagDto[]>([]);
  readonly hasPrev = input(false);
  readonly hasNext = input(false);
  /** Whether to render the in-article toolbar. False in full-screen reading,
   *  where the top bar hosts the back button, reader switch and prev/next;
   *  true in split-pane mode, where the reader has no shared top bar. */
  readonly showToolbar = input(true);

  readonly favorite = output<void>();
  readonly keep = output<void>();
  readonly read = output<void>();
  readonly prev = output<void>();
  readonly next = output<void>();
  // Semantic "back to list" output; not a DOM element's close event.
  // eslint-disable-next-line @angular-eslint/no-output-native
  readonly close = output<void>();

  private readonly content = viewChild<ElementRef<HTMLElement>>('content');
  private readonly reader = inject(ReaderContentService);
  protected readonly readerMode = inject(ReaderModeService);
  private readonly destroyRef = inject(DestroyRef);

  // The open entry's object reference changes on every optimistic flag update
  // (favorite/keep/read), but its id does not. Tracking the loaded id lets the
  // load effect ignore those churns: no redundant re-fetch, and the Reader/
  // Original toggle survives an in-reader action instead of snapping back.
  private loadedId: number | null = null;
  private loadSub: Subscription | null = null;

  // Alias the shared mode signal so the template and computeds read it directly;
  // writes go through the ReaderModeService lifecycle methods below.
  readonly mode = this.readerMode.mode;
  private readonly state = signal<
    { status: 'idle' | 'loading' } | { status: 'ok'; article: ReaderArticle } | { status: 'failed' }
  >({ status: 'idle' });

  readonly loading = computed(() => this.state().status === 'loading');
  readonly failed = computed(() => this.state().status === 'failed');
  private readonly article = computed(() => {
    const s = this.state();
    return s.status === 'ok' ? s.article : null;
  });
  // A hero image for articles whose extracted body has none (only in reader mode;
  // the original-content view keeps its own inline images).
  readonly leadImage = computed(() =>
    this.mode() === 'reader' ? (this.article()?.leadImage ?? null) : null,
  );

  readonly displayHtml = computed(() => {
    const e = this.entry();
    if (!e) return '';
    const a = this.article();
    // Original mode falls back through summary: many feeds populate only one of
    // contentHtml/summary, so preferring contentHtml then summary avoids a blank
    // pane under the "showing the feed's summary" note.
    return this.mode() === 'reader' && a ? a.contentHtml : (e.contentHtml ?? e.summary ?? '');
  });

  constructor() {
    effect(() => {
      const e = this.entry();
      const id = e?.id ?? null;
      // Only react to a genuine entry change — not to a same-entry reference
      // churn from an optimistic flag update (which must not cancel an in-flight
      // load, re-fetch, or reset the mode toggle).
      if (id === this.loadedId) return;
      this.loadedId = id;
      this.loadSub?.unsubscribe();
      this.readerMode.reset();
      if (!e) {
        this.state.set({ status: 'idle' });
        return;
      }
      this.state.set({ status: 'loading' });
      this.loadSub = this.reader
        .load(e.id)
        .pipe(timeout({ first: READER_LOAD_TIMEOUT_MS }))
        .subscribe({
          next: (c) => {
            if (c.status === 'ok') {
              this.state.set({ status: 'ok', article: c });
              this.readerMode.enableToggle();
            } else {
              this.state.set({ status: 'failed' });
              this.readerMode.setOriginalOnly();
            }
          },
          error: () => {
            this.state.set({ status: 'failed' });
            this.readerMode.setOriginalOnly();
          },
        });
    });
    this.destroyRef.onDestroy(() => this.loadSub?.unsubscribe());

    // Re-decorate external links whenever the rendered HTML changes.
    effect(() => {
      this.displayHtml();
      queueMicrotask(() => {
        const host = this.content()?.nativeElement;
        if (!host) return;
        for (const a of Array.from(host.querySelectorAll('a'))) {
          // Leave in-page fragment anchors alone; only external links open in a new tab.
          if ((a.getAttribute('href') ?? '').startsWith('#')) continue;
          if (a.target !== '_blank') {
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
          }
        }
      });
    });
  }

  toggleMode(): void {
    this.readerMode.toggle();
  }

  when(e: EntryDto): string {
    return relativeTime(e.publishedAt ?? e.createdAt);
  }
}
