import { HttpErrorResponse } from '@angular/common/http';
import { Component, computed, inject, signal } from '@angular/core';
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
import { TagDto, TagInput } from '../../models';
import { TagsStore } from '../../state/tags.store';
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
  private readonly tagsStore = inject(TagsStore);

  readonly isEdit = this.data !== null;
  readonly titleKey = this.isEdit ? 'dialog.tagForm.editTitle' : 'dialog.tagForm.newTitle';

  readonly form = this.fb.group({
    name: [this.data?.name ?? '', [Validators.required, Validators.maxLength(100)]],
  });
  readonly color = signal<string | null>(this.data?.color ?? null);
  readonly icon = signal<string | null>(this.data?.icon ?? null);
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly parentId = signal<number | null>(this.data?.parentId ?? null);
  readonly parentError = signal<string | null>(null);
  readonly parentOptions = computed(() =>
    this.tagsStore.rootTags().filter((tag) => tag.id !== this.data?.id),
  );
  readonly hasChildren = computed(
    () => this.data !== null && this.tagsStore.tags().some((tag) => tag.parentId === this.data?.id),
  );

  constructor() {
    this.tagsStore.load();
  }

  onParentChange(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    this.parentId.set(value === '' ? null : Number(value));
    this.parentError.set(null);
  }

  submit(): void {
    if (this.form.invalid) return;
    const body: TagInput = {
      name: this.form.getRawValue().name.trim(),
      color: this.color(),
      icon: this.icon(),
      parentId: this.hasChildren() ? null : this.parentId(),
    };
    this.loading.set(true);
    this.error.set(null);
    this.parentError.set(null);
    const request = this.isEdit
      ? this.api.updateTag(this.data!.id, body)
      : this.api.createTag(body);
    request.subscribe({
      next: (response) => this.ref.close(response.tag),
      error: (error: HttpErrorResponse) => {
        this.loading.set(false);
        const problem = parseProblem(error);
        const parentMessage = problem.errors?.['parentId']?.[0] ?? null;
        if (parentMessage !== null) {
          this.parentError.set(parentMessage);
          return;
        }
        this.error.set(problem.errors?.['name']?.[0] ?? problem.detail ?? problem.title);
      },
    });
  }
}
