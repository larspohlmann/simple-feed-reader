import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { A11yModule } from '@angular/cdk/a11y';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { TagGlyphComponent } from '../../shared/tag-glyph/tag-glyph.component';
import { FieldComponent } from '../../shared/field/field.component';
import { OverlayPanelComponent } from '../../shared/overlay-panel/overlay-panel.component';
import { ToggleComponent } from '../../shared/toggle/toggle.component';
import { parseProblem } from '../../core/problem';
import { ReaderApi } from '../reader-api';
import { TagsStore } from '../tags.store';
import { SubscriptionDto } from '../models';
import { ButtonComponent } from '../../shared/button/button.component';

@Component({
  selector: 'app-edit-subscription-dialog',
  imports: [
    ReactiveFormsModule,
    A11yModule,
    IconComponent,
    TagGlyphComponent,
    FieldComponent,
    ButtonComponent,
    OverlayPanelComponent,
    ToggleComponent,
    TranslocoPipe,
  ],
  templateUrl: './edit-subscription-dialog.component.html',
  styleUrl: './edit-subscription-dialog.component.scss',
})
export class EditSubscriptionDialogComponent implements OnInit {
  readonly ref = inject<DialogRef<SubscriptionDto>>(DialogRef);
  readonly data = inject<SubscriptionDto>(DIALOG_DATA);
  private readonly api = inject(ReaderApi);
  readonly tagsStore = inject(TagsStore);
  private readonly fb = inject(NonNullableFormBuilder);

  // Prefilled with the title as it reads now, so renaming is an edit rather
  // than a retype. Saving it unchanged does pin the current name against a
  // later feed rename -- which is what the reset below is for.
  readonly form = this.fb.group({
    customTitle: [this.data.title, [Validators.maxLength(512)]],
  });
  readonly checked = signal<Set<number>>(new Set(this.data.tags.map((t) => t.id)));
  readonly includeInAllItems = signal<boolean>(this.data.includeInAllItems);
  readonly includeInForYou = signal<boolean>(this.data.includeInForYou);
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);

  ngOnInit(): void {
    if (this.tagsStore.tags().length === 0) this.tagsStore.load();
  }

  /** Drop the override, so the feed's own title shows through again. An empty
   *  field is what clears it server-side. */
  resetTitle(): void {
    this.form.controls.customTitle.setValue('');
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

  submit(): void {
    if (this.form.invalid) return;
    const body = {
      customTitle: this.form.getRawValue().customTitle.trim() || null,
      tagIds: [...this.checked()],
      includeInAllItems: this.includeInAllItems(),
      includeInForYou: this.includeInForYou(),
    };
    this.loading.set(true);
    this.error.set(null);
    this.api.updateSubscription(this.data.id, body).subscribe({
      next: (r) => this.ref.close(r.subscription),
      error: (e: HttpErrorResponse) => {
        this.loading.set(false);
        const p = parseProblem(e);
        this.error.set(p.errors?.['customTitle']?.[0] ?? p.detail ?? p.title);
      },
    });
  }
}
