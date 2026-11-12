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
import { toggled } from '../../../core/toggled-set';
import { translationOf } from '../../../core/i18n/translation-of';
import { parseProblem, problemText } from '../../../core/problem';
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

function inlinePreviews(candidates: FeedCandidate[]): Record<string, PreviewState> {
  const previews: Record<string, PreviewState> = {};
  for (const candidate of candidates) {
    if (candidate.preview) previews[candidate.url] = { status: 'ok', preview: candidate.preview };
  }
  return previews;
}

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
  readonly busy = signal<'discovering' | 'subscribing' | null>(null);
  readonly error = signal<string | null>(null);
  readonly candidates = signal<FeedCandidate[]>([]);
  readonly searched = signal(false);
  readonly previews = signal<Record<string, PreviewState>>({});
  readonly failureReason = signal<ScrapeFailureReason | null>(null);
  /** Tags to apply to the new feed; they persist across a search so picking a
   *  discovered candidate carries the same selection through. */
  readonly checked = signal<ReadonlySet<number>>(new Set());
  /** The one candidate card currently showing its preview rows, by URL. */
  readonly expanded = signal<string | null>(null);

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
    this.checked.update((set) => toggled(set, id));
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

  /** An unrecognized future reason falls back to a generic line rather than an empty warning box. */
  failureText(reason: ScrapeFailureReason): string {
    return this.translatedOr(`dialog.addFeed.failure.${reason}`, 'dialog.addFeed.failGeneric');
  }

  noPreviewText(reason: NoPreviewReason): string {
    return this.translatedOr(
      `dialog.addFeed.noPreview.${reason}`,
      'dialog.addFeed.noPreviewGeneric',
    );
  }

  private translatedOr(key: string, fallbackKey: string): string {
    return translationOf(this.i18n, key) ?? this.i18n.translate(fallbackKey);
  }

  submit(): void {
    if (this.form.invalid || this.busy()) return;
    this.discover(this.form.getRawValue().url);
  }

  pick(candidate: FeedCandidate): void {
    this.busy.set('subscribing');
    this.error.set(null);
    this.api
      .subscribe({
        url: candidate.url,
        format: candidate.storedFormat,
        tagIds: [...this.checked()],
        title: candidate.storedFormat ? (candidate.title ?? undefined) : undefined,
      })
      .subscribe({
        next: (response) => this.ref.close(response.subscription),
        error: (error: HttpErrorResponse) => {
          this.busy.set(null);
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

  private discover(url: string): void {
    this.busy.set('discovering');
    this.error.set(null);
    this.searched.set(false);
    this.failureReason.set(null);
    this.candidates.set([]);
    this.previews.set({});
    this.expanded.set(null);
    this.api.discoverFeeds(url).subscribe({
      next: (response) => {
        this.busy.set(null);
        if (response.scrapeFailureReason) {
          this.failureReason.set(response.scrapeFailureReason);
          return;
        }
        this.showCandidates(response.candidates);
      },
      error: (error: HttpErrorResponse) => {
        this.busy.set(null);
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
    this.error.set(problemText(parseProblem(error), this.i18n));
  }

  /** Fetches a candidate's preview once, the first time it is expanded — a
   *  second expand of the same candidate must not re-fetch. */
  private ensurePreview(candidate: FeedCandidate): void {
    if (this.previews()[candidate.url]) return;
    this.setPreview(candidate.url, { status: 'loading' });
    this.api.previewFeed(candidate.url, candidate.storedFormat).subscribe({
      next: (response) => this.setPreview(candidate.url, { status: 'ok', preview: response.feed }),
      error: (error: HttpErrorResponse) =>
        this.setPreview(candidate.url, { status: 'error', message: parseProblem(error).detail }),
    });
  }

  private setPreview(url: string, state: PreviewState): void {
    this.previews.update((previewsByUrl) => ({ ...previewsByUrl, [url]: state }));
  }
}
