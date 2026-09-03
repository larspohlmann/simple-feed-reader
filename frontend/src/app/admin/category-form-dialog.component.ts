import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { A11yModule } from '@angular/cdk/a11y';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { TranslocoPipe } from '@jsverse/transloco';
import { parseProblem } from '../core/problem';
import { ButtonComponent } from '../shared/button/button.component';
import { ColorFieldComponent } from '../shared/color-field/color-field.component';
import { FieldComponent } from '../shared/field/field.component';
import { IconPickerComponent } from '../shared/icon-picker/icon-picker.component';
import { OverlayPanelComponent } from '../shared/overlay-panel/overlay-panel.component';
import { AdminApi } from './admin-api';
import { AdminCatalogCategoryDto, DEFAULT_CATEGORY_COLOR } from './admin.models';

/** Create or edit a catalog category. The dialog performs its own API write and
 *  closes with the saved entity — the same contract as the tag form. */
@Component({
  selector: 'app-category-form-dialog',
  imports: [
    ReactiveFormsModule,
    A11yModule,
    ButtonComponent,
    ColorFieldComponent,
    FieldComponent,
    IconPickerComponent,
    OverlayPanelComponent,
    TranslocoPipe,
  ],
  templateUrl: './category-form-dialog.component.html',
  styleUrl: './category-form-dialog.component.scss',
})
export class CategoryFormDialogComponent {
  readonly ref = inject<DialogRef<AdminCatalogCategoryDto>>(DialogRef);
  readonly data = inject<AdminCatalogCategoryDto | null>(DIALOG_DATA);
  private readonly api = inject(AdminApi);
  private readonly fb = inject(NonNullableFormBuilder);

  readonly isEdit = this.data !== null;
  readonly titleKey = this.isEdit
    ? 'admin.categoryDialog.editTitle'
    : 'admin.categoryDialog.newTitle';

  readonly form = this.fb.group({
    name: [this.data?.name ?? '', [Validators.required, Validators.maxLength(100)]],
    enabled: [this.data?.enabled ?? true],
    locked: [this.data?.locked ?? false],
  });
  readonly icon = signal(this.data?.icon ?? '');
  readonly color = signal(this.data?.color ?? DEFAULT_CATEGORY_COLOR);
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);

  /** A category always carries a colour; the clear-less colour field never
   *  emits null, and the guard states that invariant. */
  applyColor(color: string | null): void {
    if (color !== null) this.color.set(color);
  }

  submit(): void {
    if (this.form.invalid) return;
    const value = this.form.getRawValue();
    const body = {
      key: this.data?.key ?? '',
      name: value.name.trim(),
      icon: this.icon(),
      color: this.color(),
      enabled: value.enabled,
      locked: value.locked,
    };
    this.loading.set(true);
    this.error.set(null);
    this.api.saveCategory(this.data?.id ?? null, body).subscribe({
      next: (result) => this.ref.close(result.category),
      error: (failure: HttpErrorResponse) => {
        this.loading.set(false);
        const problem = parseProblem(failure);
        this.error.set(problem.errors?.['name']?.[0] ?? problem.detail ?? problem.title);
      },
    });
  }
}
