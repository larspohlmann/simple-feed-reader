// src/app/reader/manage/tag-form-dialog.component.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { A11yModule } from '@angular/cdk/a11y';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';
import { TranslocoPipe } from '@jsverse/transloco';
import { parseProblem } from '../../core/problem';
import { IconComponent } from '../../shared/icon/icon.component';
import { ReaderApi } from '../reader-api';
import { TagDto } from '../models';
import { TAG_COLORS, TAG_ICONS } from './icon-choices';

@Component({
  selector: 'app-tag-form-dialog',
  imports: [ReactiveFormsModule, A11yModule, IconComponent, TranslocoPipe],
  templateUrl: './tag-form-dialog.component.html',
  styleUrl: './tag-form-dialog.component.scss',
})
export class TagFormDialogComponent {
  readonly ref = inject<DialogRef<TagDto>>(DialogRef);
  readonly data = inject<TagDto | null>(DIALOG_DATA);
  private readonly api = inject(ReaderApi);
  private readonly fb = inject(NonNullableFormBuilder);

  readonly colors = TAG_COLORS;
  readonly icons = TAG_ICONS;
  readonly isEdit = this.data !== null;
  readonly titleKey = this.isEdit ? 'dialog.tagForm.editTitle' : 'dialog.tagForm.newTitle';

  readonly form = this.fb.group({
    name: [this.data?.name ?? '', [Validators.required, Validators.maxLength(100)]],
  });
  readonly color = signal<string | null>(this.data?.color ?? null);
  readonly icon = signal<string | null>(this.data?.icon ?? null);
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);

  pickerValue(e: Event): string {
    return (e.target as HTMLInputElement).value;
  }

  submit(): void {
    if (this.form.invalid) return;
    const body = {
      name: this.form.getRawValue().name.trim(),
      color: this.color(),
      icon: this.icon(),
    };
    this.loading.set(true);
    this.error.set(null);
    const req = this.isEdit ? this.api.updateTag(this.data!.id, body) : this.api.createTag(body);
    req.subscribe({
      next: (r) => this.ref.close(r.tag),
      error: (e: HttpErrorResponse) => {
        this.loading.set(false);
        const p = parseProblem(e);
        this.error.set(p.errors?.['name']?.[0] ?? p.detail ?? p.title);
      },
    });
  }
}
