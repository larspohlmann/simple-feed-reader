// src/app/admin/feed-form-dialog.component.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { A11yModule } from '@angular/cdk/a11y';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { TranslocoPipe } from '@jsverse/transloco';
import { parseProblem } from '../core/problem';
import { ButtonComponent } from '../shared/button/button.component';
import { FieldComponent } from '../shared/field/field.component';
import { OverlayPanelComponent } from '../shared/overlay-panel/overlay-panel.component';
import { AdminApi } from './admin-api';
import { AdminCatalogCategoryDto, AdminCatalogFeedDto } from './admin.models';

export interface FeedFormData {
  /** null → create. */
  feed: AdminCatalogFeedDto | null;
  categories: AdminCatalogCategoryDto[];
  /** Preselected category for a new feed — the block whose Add button opened us. */
  categoryId: number;
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

  readonly form = this.fb.group({
    title: [this.data.feed?.title ?? '', [Validators.required, Validators.maxLength(255)]],
    url: [this.data.feed?.url ?? '', [Validators.required]],
    siteUrl: [this.data.feed?.siteUrl ?? ''],
    description: [this.data.feed?.description ?? ''],
    categoryId: [this.data.feed?.categoryId ?? this.data.categoryId],
    enabled: [this.data.feed?.enabled ?? true],
    locked: [this.data.feed?.locked ?? false],
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
