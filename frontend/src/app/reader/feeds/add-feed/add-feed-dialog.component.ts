import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { A11yModule } from '@angular/cdk/a11y';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { TagPickerComponent } from '../tag-picker/tag-picker.component';
import { FieldComponent } from '../../../shared/field/field.component';
import { OverlayPanelComponent } from '../../../shared/overlay-panel/overlay-panel.component';
import { SpinnerComponent } from '../../../shared/spinner/spinner.component';
import { SkeletonComponent } from '../../../shared/skeleton/skeleton.component';
import { PreviewEntryRowComponent } from './preview-entry-row/preview-entry-row.component';
import { parseProblem } from '../../../core/problem';
import { ReaderApi } from '../../reader-api';
import { TagsStore } from '../../state/tags.store';
import {
  FeedCandidate,
  FeedPreview,
  NoPreviewReason,
  ScrapeFailureReason,
  SubscriptionDto,
} from '../../models';
import { ButtonComponent } from '../../../shared/button/button.component';

type PreviewState =
  | { status: 'loading' }
  | { status: 'error'; message?: string }
  | { status: 'ok'; preview: FeedPreview };

const FAILURE_TEXT_KEYS = new Map<ScrapeFailureReason, string>([
  ['blocked', 'failBlocked'],
  ['throttled', 'failThrottled'],
  ['unreachable', 'failUnreachable'],
  ['not_scrapable', 'failNotScrapable'],
  ['too_large', 'failTooLarge'],
]);

const NO_PREVIEW_TEXT_KEYS = new Map<NoPreviewReason, string>([
  ['hidden_profile', 'noPreviewHiddenProfile'],
  ['connect_bluesky_account', 'noPreviewConnectBluesky'],
]);

/** Formats whose candidate URL is the source itself: the backend stores it as offered. */
const VERBATIM_FORMATS: ReadonlySet<string> = new Set(['scraped', 'wp-json', 'mixcloud-api']);

const FORMAT_LABELS = new Map<string, string>([
  ['rss', 'RSS'],
  ['atom', 'Atom'],
  ['wp-json', 'WordPress'],
  ['mixcloud-api', 'Mixcloud'],
  ['bluesky-api', 'Bluesky'],
]);

function inlinePreviews(candidates: FeedCandidate[]): Record<string, PreviewState> {
  const previews: Record<string, PreviewState> = {};
  for (const candidate of candidates) {
    if (candidate.preview) previews[candidate.url] = { status: 'ok', preview: candidate.preview };
  }
  return previews;
}

/** Opens the dialog with `url` filled in, waiting for the user's tags and Add. */
export interface AddFeedDialogData {
  url: string;
}

@Component({
  selector: 'app-add-feed-dialog',
  imports: [
    ReactiveFormsModule,
    A11yModule,
    RouterLink,
    TagPickerComponent,
    FieldComponent,
    ButtonComponent,
    OverlayPanelComponent,
    SpinnerComponent,
    SkeletonComponent,
    PreviewEntryRowComponent,
    TranslocoPipe,
  ],
  templateUrl: './add-feed-dialog.component.html',
  styleUrl: './add-feed-dialog.component.scss',
})
export class AddFeedDialogComponent implements OnInit {
  readonly ref = inject<DialogRef<SubscriptionDto>>(DialogRef);
  private readonly data = inject<AddFeedDialogData | undefined>(DIALOG_DATA);
  private readonly api = inject(ReaderApi);
  readonly tagsStore = inject(TagsStore);
  private readonly fb = inject(NonNullableFormBuilder);
  private readonly i18n = inject(TranslocoService);

  readonly form = this.fb.group({ url: [this.data?.url ?? '', [Validators.required]] });
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly candidates = signal<FeedCandidate[]>([]);
  readonly searched = signal(false);
  readonly previews = signal<Record<string, PreviewState>>({});
  readonly failureReason = signal<ScrapeFailureReason | null>(null);
  /** Tags to apply to the new feed; they persist across a search so picking a
   *  discovered candidate carries the same selection through. */
  readonly checked = signal<Set<number>>(new Set());
  /** The one candidate card currently showing its preview rows, by URL. */
  readonly expanded = signal<string | null>(null);
  readonly subscribing = signal(false);

  constructor() {
    // A scrape failure is about the URL as typed; editing it starts over.
    this.form.controls.url.valueChanges
      .pipe(takeUntilDestroyed())
      .subscribe(() => this.failureReason.set(null));
  }

  ngOnInit(): void {
    if (this.tagsStore.tags().length === 0) this.tagsStore.load();
  }

  toggleTag(id: number): void {
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
    const label = FORMAT_LABELS.get(format);
    if (label) return label;
    return format ? format.charAt(0).toUpperCase() + format.slice(1) : 'Feed';
  }

  /**
   * Why a scrape-fallback failure means this URL is a dead end, in words. The
   * default keeps an unrecognized future reason from rendering an empty warning
   * box — the backend's reason set is open, so this build may not know them all.
   */
  failureText(reason: ScrapeFailureReason): string {
    const key = FAILURE_TEXT_KEYS.get(reason) ?? 'failGeneric';
    return this.i18n.translate(`dialog.addFeed.${key}`);
  }

  noPreviewText(reason: NoPreviewReason): string {
    const key = NO_PREVIEW_TEXT_KEYS.get(reason) ?? 'noPreviewGeneric';
    return this.i18n.translate(`dialog.addFeed.${key}`);
  }

  submit(): void {
    if (this.form.invalid) return;
    this.discover(this.form.getRawValue().url);
  }

  pick(candidate: FeedCandidate): void {
    const format = this.storedFormat(candidate);
    const passesTitle = format !== undefined && format !== 'scraped';
    this.subscribing.set(true);
    this.error.set(null);
    this.api
      .subscribe({
        url: candidate.url,
        format,
        tagIds: [...this.checked()],
        title: passesTitle ? (candidate.title ?? undefined) : undefined,
      })
      .subscribe({
        next: (response) => this.ref.close(response.subscription),
        error: (error: HttpErrorResponse) => {
          this.subscribing.set(false);
          this.showError(error);
        },
      });
  }

  /** Expands or collapses a candidate's preview rows; expanding fetches the
   *  preview lazily, only for the candidate the user actually opens. */
  toggle(candidate: FeedCandidate): void {
    if (this.expanded() === candidate.url) {
      this.expanded.set(null);
      return;
    }
    this.expanded.set(candidate.url);
    this.ensurePreview(candidate);
  }

  private storedFormat(candidate: FeedCandidate): string | undefined {
    return VERBATIM_FORMATS.has(candidate.format) ? candidate.format : undefined;
  }

  private discover(url: string): void {
    this.loading.set(true);
    this.error.set(null);
    this.searched.set(false);
    this.failureReason.set(null);
    // Start every search visually clean — a previous search's cards must not
    // linger above a new result, least of all above a scrape-failure warning.
    this.candidates.set([]);
    this.previews.set({});
    this.expanded.set(null);
    this.api.discoverFeeds(url).subscribe({
      next: (response) => {
        this.loading.set(false);
        if (response.scrapeFailureReason) {
          this.failureReason.set(response.scrapeFailureReason);
          return;
        }
        this.showCandidates(response.candidates);
      },
      error: (error: HttpErrorResponse) => {
        this.loading.set(false);
        this.showError(error);
      },
    });
  }

  /** Opens the first card so a preview is always in view: discovery orders native feeds first. */
  private showCandidates(candidates: FeedCandidate[]): void {
    this.previews.set(inlinePreviews(candidates));
    this.candidates.set(candidates);
    this.searched.set(true);
    const first = candidates[0];
    if (first && !first.noPreviewReason) this.toggle(first);
  }

  private showError(error: HttpErrorResponse): void {
    const problem = parseProblem(error);
    this.error.set(problem.errors?.['url']?.[0] ?? problem.detail ?? problem.title);
  }

  /** Fetches a candidate's preview once, the first time it is expanded — a
   *  second expand of the same candidate must not re-fetch. */
  private ensurePreview(candidate: FeedCandidate): void {
    if (this.previews()[candidate.url]) {
      return;
    }
    this.previews.update((previewsByUrl) => ({
      ...previewsByUrl,
      [candidate.url]: { status: 'loading' },
    }));
    this.api.previewFeed(candidate.url, this.storedFormat(candidate)).subscribe({
      next: (response) =>
        this.previews.update((previewsByUrl) => ({
          ...previewsByUrl,
          [candidate.url]: { status: 'ok', preview: response.feed },
        })),
      error: (error: HttpErrorResponse) =>
        this.previews.update((previewsByUrl) => ({
          ...previewsByUrl,
          [candidate.url]: { status: 'error', message: parseProblem(error).detail },
        })),
    });
  }
}
