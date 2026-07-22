// src/app/reader/manage/tag-form-dialog.component.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { A11yModule } from '@angular/cdk/a11y';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';
import { parseProblem } from '../../core/problem';
import { IconComponent } from '../../shared/icon/icon.component';
import { ReaderApi } from '../reader-api';
import { TagDto } from '../models';
import { TAG_COLORS, TAG_ICONS } from './icon-choices';

@Component({
  selector: 'app-tag-form-dialog',
  imports: [ReactiveFormsModule, A11yModule, IconComponent],
  template: `
    <div class="dialog" role="dialog" aria-modal="true" [attr.aria-label]="title" cdkTrapFocus>
      <h2>{{ title }}</h2>
      <form [formGroup]="form" (ngSubmit)="submit()">
        <label class="lbl" for="tag-name">Name</label>
        <input id="tag-name" class="field" formControlName="name" maxlength="100" cdkFocusInitial />

        <p class="lbl">Colour</p>
        <div class="swatches">
          @for (c of colors; track c) {
            <button
              type="button"
              class="swatch"
              [class.on]="color() === c"
              [style.background]="c"
              [attr.aria-label]="'Colour ' + c"
              (click)="color.set(c)"
            ></button>
          }
          <input
            type="color"
            class="picker"
            aria-label="Custom colour"
            [value]="color() ?? '#3f8676'"
            (input)="color.set(pickerValue($event))"
          />
          <button type="button" class="clear" (click)="color.set(null)">None</button>
        </div>

        <p class="lbl">Icon</p>
        <div class="icons">
          <button
            type="button"
            class="icon"
            [class.on]="icon() === null"
            aria-label="No icon"
            (click)="icon.set(null)"
          >
            <app-icon name="block" [size]="18" />
          </button>
          @for (i of icons; track i) {
            <button
              type="button"
              class="icon"
              [class.on]="icon() === i"
              [attr.aria-label]="i"
              (click)="icon.set(i)"
            >
              <app-icon [name]="i" [size]="18" />
            </button>
          }
        </div>

        @if (error()) {
          <p class="error" role="alert">{{ error() }}</p>
        }
        <div class="row">
          <button type="button" (click)="ref.close()">Cancel</button>
          <button type="submit" class="primary" [disabled]="loading()">
            {{ loading() ? 'Saving…' : 'Save' }}
          </button>
        </div>
      </form>
    </div>
  `,
  styles: [
    `
      .dialog {
        background: var(--surface-2);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: var(--space-5);
        width: min(460px, 92vw);
        display: flex;
        flex-direction: column;
        gap: var(--space-3);
      }
      h2 {
        margin: 0;
        font-size: var(--fs-lg);
      }
      form {
        display: flex;
        flex-direction: column;
        gap: var(--space-2);
      }
      .lbl {
        margin: var(--space-2) 0 0;
        font-size: var(--fs-sm);
        color: var(--text-secondary);
      }
      .swatches {
        display: flex;
        flex-wrap: wrap;
        gap: var(--space-2);
        align-items: center;
      }
      .swatch {
        width: 22px;
        height: 22px;
        border-radius: 50%;
        border: 2px solid var(--border);
        cursor: pointer;
      }
      .swatch.on {
        border-color: var(--text-primary);
      }
      .picker {
        width: 30px;
        height: 26px;
        padding: 0;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        background: none;
        cursor: pointer;
      }
      .clear {
        font-size: var(--fs-sm);
        background: var(--surface-1);
        border: 1px solid var(--border-strong);
        border-radius: var(--radius);
        color: var(--text-secondary);
        padding: 0 var(--space-2);
        cursor: pointer;
      }
      .icons {
        display: flex;
        flex-wrap: wrap;
        gap: var(--space-1);
      }
      .icon {
        display: inline-flex;
        padding: var(--space-2);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        background: var(--surface-1);
        color: var(--text-secondary);
        cursor: pointer;
      }
      .icon.on {
        border-color: var(--accent);
        color: var(--accent);
        background: var(--accent-soft);
      }
      .error {
        color: var(--danger);
        font-size: var(--fs-sm);
        margin: 0;
      }
      .row {
        display: flex;
        justify-content: flex-end;
        gap: var(--space-2);
        margin-top: var(--space-2);
      }
      .row button {
        padding: var(--space-2) var(--space-4);
        border: 1px solid var(--border-strong);
        border-radius: var(--radius);
        background: var(--surface-1);
        color: var(--text-primary);
        cursor: pointer;
      }
      .row button.primary {
        background: var(--accent);
        color: var(--on-accent);
        border-color: var(--accent);
      }
    `,
  ],
})
export class TagFormDialogComponent {
  readonly ref = inject<DialogRef<TagDto>>(DialogRef);
  readonly data = inject<TagDto | null>(DIALOG_DATA);
  private readonly api = inject(ReaderApi);
  private readonly fb = inject(NonNullableFormBuilder);

  readonly colors = TAG_COLORS;
  readonly icons = TAG_ICONS;
  readonly isEdit = this.data !== null;
  readonly title = this.isEdit ? 'Edit tag' : 'New tag';

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
