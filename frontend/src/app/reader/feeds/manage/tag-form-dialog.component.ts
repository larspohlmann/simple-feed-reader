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
import { MAX_TAG_DEPTH, TagHierarchy } from '../../state/tag-hierarchy';
import { tagLabel, tagsInTreeOrder } from '../../state/subscription-derivations';
import { TagsStore } from '../../state/tags.store';
import { ButtonComponent } from '../../../shared/button/button.component';

export type TagFormData =
  { kind: 'edit'; tag: TagDto } | { kind: 'create'; parentId: number | null };

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
  readonly data = inject<TagFormData>(DIALOG_DATA);
  private readonly api = inject(ReaderApi);
  private readonly fb = inject(NonNullableFormBuilder);
  private readonly tagsStore = inject(TagsStore);

  private readonly editedTag = this.data.kind === 'edit' ? this.data.tag : null;
  readonly titleKey =
    this.editedTag !== null ? 'dialog.tagForm.editTitle' : 'dialog.tagForm.newTitle';

  readonly form = this.fb.group({
    name: [this.editedTag?.name ?? '', [Validators.required, Validators.maxLength(100)]],
  });
  readonly color = signal<string | null>(this.editedTag?.color ?? null);
  readonly icon = signal<string | null>(this.editedTag?.icon ?? null);
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);
  readonly parentId = signal<number | null>(
    this.data.kind === 'edit' ? this.data.tag.parentId : this.data.parentId,
  );
  readonly parentError = signal<string | null>(null);
  private readonly hierarchy = computed(() => new TagHierarchy(this.tagsStore.tags()));
  readonly parentOptions = computed(() => {
    const tags = this.tagsStore.tags();
    const hierarchy = this.hierarchy();
    return tagsInTreeOrder(tags)
      .filter((tag) => hierarchy.mayPlaceUnder(this.editedTag?.id ?? null, tag.id))
      .map((tag) => ({ id: tag.id, label: tagLabel(tag, tags) }));
  });
  readonly parentLocked = computed(
    () => this.editedTag !== null && this.hierarchy().heightOf(this.editedTag.id) >= MAX_TAG_DEPTH,
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
      parentId: this.parentLocked() ? null : this.parentId(),
    };
    this.loading.set(true);
    this.error.set(null);
    this.parentError.set(null);
    const request =
      this.editedTag !== null
        ? this.api.updateTag(this.editedTag.id, body)
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
