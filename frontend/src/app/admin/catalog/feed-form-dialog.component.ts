import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { A11yModule } from '@angular/cdk/a11y';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { TranslocoPipe } from '@jsverse/transloco';
import { parseProblem } from '../../core/problem';
import { ButtonComponent } from '../../shared/button/button.component';
import { FieldComponent } from '../../shared/field/field.component';
import { OverlayPanelComponent } from '../../shared/overlay-panel/overlay-panel.component';
import { AdminApi } from '../admin-api';
import { AdminCatalogCategoryDto, AdminCatalogFeedDto } from '../admin.models';

export interface FeedFormData {
  /** null → create. */
  feed: AdminCatalogFeedDto | null;
  categories: AdminCatalogCategoryDto[];
  /** Preselected category for a new feed — the block whose Add button opened us. */
  categoryId: number;
}

interface FeedFormValues {
  title: string;
  url: string;
  siteUrl: string;
  description: string;
  categoryId: number;
  enabled: boolean;
  locked: boolean;
}

function initialFeedValues({ feed, categoryId }: FeedFormData): FeedFormValues {
  if (feed === null) {
    return {
      title: '',
      url: '',
      siteUrl: '',
      description: '',
      categoryId,
      enabled: true,
      locked: false,
    };
  }

  return {
    title: feed.title,
    url: feed.url,
    siteUrl: feed.siteUrl ?? '',
    description: feed.description ?? '',
    categoryId: feed.categoryId,
    enabled: feed.enabled,
    locked: feed.locked,
  };
}

/** Create or edit a catalog feed. Performs its own API write and closes with
 *  the saved entity — the same contract as the tag form. */
@Component({
  selector: 'app-feed-form-dialog',
  imports: [
    ReactiveFormsModule,
    A11yModule,
    ButtonComponent,
    FieldComponent,
    OverlayPanelComponent,
    TranslocoPipe,
  ],
  templateUrl: './feed-form-dialog.component.html',
  styleUrl: './feed-form-dialog.component.scss',
})
export class FeedFormDialogComponent {
  readonly ref = inject<DialogRef<AdminCatalogFeedDto>>(DialogRef);
  readonly data = inject<FeedFormData>(DIALOG_DATA);
  private readonly api = inject(AdminApi);
  private readonly fb = inject(NonNullableFormBuilder);

  readonly isEdit = this.data.feed !== null;
  readonly titleKey = this.isEdit ? 'admin.feedDialog.editTitle' : 'admin.feedDialog.newTitle';

  private readonly initial = initialFeedValues(this.data);

  readonly form = this.fb.group({
    title: [this.initial.title, [Validators.required, Validators.maxLength(255)]],
    url: [this.initial.url, [Validators.required]],
    siteUrl: [this.initial.siteUrl],
    description: [this.initial.description],
    categoryId: [this.initial.categoryId],
    enabled: [this.initial.enabled],
    locked: [this.initial.locked],
  });
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);

  submit(): void {
    if (this.form.invalid) return;
    const value = this.form.getRawValue();
    const body = {
      categoryId: Number(value.categoryId),
      title: value.title.trim(),
      url: value.url.trim(),
      siteUrl: value.siteUrl.trim() || null,
      description: value.description.trim() || null,
      sourceFormat: this.data.feed?.sourceFormat ?? 'xml',
      enabled: value.enabled,
      locked: value.locked,
    };
    this.loading.set(true);
    this.error.set(null);
    this.api.saveFeed(this.data.feed?.id ?? null, body).subscribe({
      next: (result) => this.ref.close(result.feed),
      error: (failure: HttpErrorResponse) => {
        this.loading.set(false);
        const problem = parseProblem(failure);
        this.error.set(problem.errors?.['url']?.[0] ?? problem.detail ?? problem.title);
      },
    });
  }
}
