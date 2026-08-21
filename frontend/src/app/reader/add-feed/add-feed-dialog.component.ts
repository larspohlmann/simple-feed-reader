// src/app/reader/add-feed/add-feed-dialog.component.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { A11yModule } from '@angular/cdk/a11y';
import { DialogRef } from '@angular/cdk/dialog';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { TagGlyphComponent } from '../../shared/tag-glyph/tag-glyph.component';
import { FieldComponent } from '../../shared/field/field.component';
import { OverlayPanelComponent } from '../../shared/overlay-panel/overlay-panel.component';
import { SpinnerComponent } from '../../shared/spinner/spinner.component';
import { parseProblem } from '../../core/problem';
import { ReaderApi } from '../reader-api';
import { TagsStore } from '../tags.store';
import { FeedCandidate, FeedPreview, ScrapeFailureReason, SubscriptionDto } from '../models';
import { ButtonComponent } from '../../shared/button/button.component';

type PreviewState =
  | { status: 'loading' }
  | { status: 'error'; message?: string }
  | { status: 'ok'; preview: FeedPreview };

@Component({
  selector: 'app-add-feed-dialog',
  imports: [
    ReactiveFormsModule,
    A11yModule,
    RouterLink,
    TagGlyphComponent,
    FieldComponent,
    ButtonComponent,
    OverlayPanelComponent,
    SpinnerComponent,
    TranslocoPipe,
  ],
  templateUrl: './add-feed-dialog.component.html',
  styleUrl: './add-feed-dialog.component.scss',
})
export class AddFeedDialogComponent implements OnInit {
  readonly ref = inject<DialogRef<SubscriptionDto>>(DialogRef);
  private readonly api = inject(ReaderApi);
  readonly tagsStore = inject(TagsStore);
  private readonly fb = inject(NonNullableFormBuilder);
  private readonly i18n = inject(TranslocoService);

  readonly form = this.fb.group({ url: ['', [Validators.required]] });
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly candidates = signal<FeedCandidate[]>([]);
  readonly searched = signal(false);
  readonly previews = signal<Record<string, PreviewState>>({});
  readonly failureReason = signal<ScrapeFailureReason | null>(null);
  /** Tags to apply to the new feed; they persist across a search so picking a
   *  discovered candidate carries the same selection through. */
  readonly checked = signal<Set<number>>(new Set());

  constructor() {
    // A scrape failure is about the URL as typed; editing it starts over.
    this.form.controls.url.valueChanges
      .pipe(takeUntilDestroyed())
      .subscribe(() => this.failureReason.set(null));
  }

  ngOnInit(): void {
    if (this.tagsStore.tags().length === 0) this.tagsStore.load();
  }

  toggle(id: number): void {
    this.checked.update((set) => {
      const next = new Set(set);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  }

  okPreview(state: PreviewState | undefined): FeedPreview | null {
    return state?.status === 'ok' ? state.preview : null;
  }

  /** The backend's problem `detail` when the preview failed (e.g. "No article
   *  list was detected on the page."), falling back to a generic line when the
   *  error carried no message (network error, non-problem body). */
  errorMessage(state: PreviewState | undefined): string {
    return (
      (state?.status === 'error' ? state.message : undefined) ??
      this.i18n.translate('dialog.addFeed.previewUnavailable')
    );
  }

  contentLabel(content: FeedPreview['content']): string {
    const key =
      content === 'full'
        ? 'contentFull'
        : content === 'summary'
          ? 'contentSummary'
          : 'contentTitles';
    return this.i18n.translate(`dialog.addFeed.${key}`);
  }

  /** Human label for a candidate's feed syntax; capitalizes any future value
   *  (e.g. a scraped/generated feed) rather than assuming only RSS/Atom. */
  formatLabel(format: string): string {
    if (format === 'rss') return 'RSS';
    if (format === 'atom') return 'Atom';
    if (format === 'wp-json') return 'WordPress';
    return format ? format.charAt(0).toUpperCase() + format.slice(1) : 'Feed';
  }

  /**
   * Why a scrape-fallback failure means this URL is a dead end, in words. The
   * default keeps an unrecognized future reason from rendering an empty warning
   * box — the backend's reason set is open, so this build may not know them all.
   */
  failureText(reason: ScrapeFailureReason): string {
    const key =
      reason === 'blocked'
        ? 'failBlocked'
        : reason === 'throttled'
          ? 'failThrottled'
          : reason === 'unreachable'
            ? 'failUnreachable'
            : reason === 'not_scrapable'
              ? 'failNotScrapable'
              : 'failGeneric';
    return this.i18n.translate(`dialog.addFeed.${key}`);
  }

  submit(): void {
    if (this.form.invalid) return;
    this.subscribe(this.form.getRawValue().url);
  }

  pick(c: FeedCandidate): void {
    this.subscribe(c.url, this.storedFormat(c));
  }

  /** Formats the backend must persist verbatim (it cannot re-derive them by
   *  parsing): scraped pages and WordPress REST endpoints. Others re-run
   *  discovery, so they pass no format. */
  private storedFormat(c: FeedCandidate): string | undefined {
    return c.format === 'scraped' || c.format === 'wp-json' ? c.format : undefined;
  }

  private subscribe(url: string, format?: string): void {
    this.loading.set(true);
    this.error.set(null);
    this.searched.set(false);
    this.failureReason.set(null);
    // Start every attempt visually clean: a previous search's candidate cards
    // (and their Subscribe buttons) must not linger above a new result — least
    // of all above a scrape-failure warning, where offering subscribe would
    // contradict the warning.
    this.candidates.set([]);
    this.previews.set({});
    this.api.subscribe(url, format, [...this.checked()]).subscribe({
      next: (res) => {
        this.loading.set(false);
        if ('subscription' in res) this.ref.close(res.subscription);
        else if (res.scrapeFailureReason) {
          this.failureReason.set(res.scrapeFailureReason);
          this.searched.set(false);
        } else {
          this.candidates.set(res.candidates);
          this.searched.set(true);
          this.loadPreviews(res.candidates);
        }
      },
      error: (e: HttpErrorResponse) => {
        this.loading.set(false);
        const p = parseProblem(e);
        this.error.set(p.errors?.['url']?.[0] ?? p.detail ?? p.title);
      },
    });
  }

  private loadPreviews(candidates: FeedCandidate[]): void {
    this.previews.set(Object.fromEntries(candidates.map((c) => [c.url, { status: 'loading' }])));
    for (const c of candidates) {
      this.api.previewFeed(c.url, this.storedFormat(c)).subscribe({
        next: (r) =>
          this.previews.update((m) => ({ ...m, [c.url]: { status: 'ok', preview: r.feed } })),
        error: (e: HttpErrorResponse) =>
          this.previews.update((m) => ({
            ...m,
            [c.url]: { status: 'error', message: parseProblem(e).detail },
          })),
      });
    }
  }
}
