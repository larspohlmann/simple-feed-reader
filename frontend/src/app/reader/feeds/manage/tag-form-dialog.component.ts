import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { A11yModule } from '@angular/cdk/a11y';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';
import { TranslocoPipe } from '@jsverse/transloco';
import { parseProblem } from '../../../core/problem';
import { ColorFieldComponent } from '../../../shared/color-field/color-field.component';
import { IconPickerComponent } from '../../../shared/icon-picker/icon-picker.component';
import { FieldComponent } from '../../../shared/field/field.component';
import { OverlayPanelComponent } from '../../../shared/overlay-panel/overlay-panel.component';
import { ReaderApi } from '../../reader-api';
import { TagDto } from '../../models';
import { ButtonComponent } from '../../../shared/button/button.component';

@Component({
  selector: 'app-tag-form-dialog',
  imports: [
    ReactiveFormsModule,
    A11yModule,
    ColorFieldComponent,
    IconPickerComponent,
    FieldComponent,
    ButtonComponent,
    OverlayPanelComponent,
    TranslocoPipe,
  ],
  templateUrl: './tag-form-dialog.component.html',
  styleUrl: './tag-form-dialog.component.scss',
})
export class TagFormDialogComponent {
  readonly ref = inject<DialogRef<TagDto>>(DialogRef);
  readonly data = inject<TagDto | null>(DIALOG_DATA);
  private readonly api = inject(ReaderApi);
  private readonly fb = inject(NonNullableFormBuilder);

  readonly isEdit = this.data !== null;
  readonly titleKey = this.isEdit ? 'dialog.tagForm.editTitle' : 'dialog.tagForm.newTitle';

  readonly form = this.fb.group({
    name: [this.data?.name ?? '', [Validators.required, Validators.maxLength(100)]],
  });
  readonly color = signal<string | null>(this.data?.color ?? null);
  readonly icon = signal<string | null>(this.data?.icon ?? null);
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);

  submit(): void {
    if (this.form.invalid) return;
    const body = {
      name: this.form.getRawValue().name.trim(),
      color: this.color(),
      icon: this.icon(),
    };
    this.loading.set(true);
    this.error.set(null);
    const request = this.isEdit
      ? this.api.updateTag(this.data!.id, body)
      : this.api.createTag(body);
    request.subscribe({
      next: (response) => this.ref.close(response.tag),
      error: (error: HttpErrorResponse) => {
        this.loading.set(false);
        const problem = parseProblem(error);
        this.error.set(problem.errors?.['name']?.[0] ?? problem.detail ?? problem.title);
      },
    });
  }
}
