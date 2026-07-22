// src/app/reader/manage/edit-subscription-dialog.component.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, inject, signal } from '@angular/core';
import { NonNullableFormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { A11yModule } from '@angular/cdk/a11y';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';
import { parseProblem } from '../../core/problem';
import { ReaderApi } from '../reader-api';
import { TagsStore } from '../tags.store';
import { SubscriptionDto } from '../models';

@Component({
  selector: 'app-edit-subscription-dialog',
  imports: [ReactiveFormsModule, A11yModule],
  template: `
    <div class="dialog" role="dialog" aria-modal="true" aria-label="Edit feed" cdkTrapFocus>
      <h2>Edit feed</h2>
      <form [formGroup]="form" (ngSubmit)="submit()">
        <label class="lbl" for="sub-title">Custom title</label>
        <input
          id="sub-title"
          class="field"
          formControlName="customTitle"
          maxlength="512"
          [placeholder]="data.title"
          cdkFocusInitial
        />

        <p class="lbl">Tags</p>
        @if (tagsStore.tags().length === 0) {
          <p class="hint">No tags yet — create one from Settings › Tags.</p>
        }
        <ul class="tags">
          @for (t of tagsStore.tags(); track t.id) {
            <li>
              <label>
                <input type="checkbox" [checked]="checked().has(t.id)" (change)="toggle(t.id)" />
                <span class="dot" [style.background]="t.color || 'var(--text-muted)'"></span>
                {{ t.name }}
              </label>
            </li>
          }
        </ul>

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
        width: min(440px, 92vw);
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
      .hint {
        margin: 0;
        font-size: var(--fs-sm);
        color: var(--text-muted);
      }
      .tags {
        list-style: none;
        margin: 0;
        padding: 0;
        max-height: 220px;
        overflow: auto;
        display: flex;
        flex-direction: column;
        gap: var(--space-1);
      }
      .tags label {
        display: flex;
        align-items: center;
        gap: var(--space-2);
        cursor: pointer;
      }
      .dot {
        width: 9px;
        height: 9px;
        border-radius: 50%;
        flex: 0 0 auto;
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
export class EditSubscriptionDialogComponent implements OnInit {
  readonly ref = inject<DialogRef<SubscriptionDto>>(DialogRef);
  readonly data = inject<SubscriptionDto>(DIALOG_DATA);
  private readonly api = inject(ReaderApi);
  readonly tagsStore = inject(TagsStore);
  private readonly fb = inject(NonNullableFormBuilder);

  readonly form = this.fb.group({
    customTitle: [this.data.customTitle ?? '', [Validators.maxLength(512)]],
  });
  readonly checked = signal<Set<number>>(new Set(this.data.tags.map((t) => t.id)));
  readonly loading = signal(false);
  readonly error = signal<string | null>(null);

  ngOnInit(): void {
    if (this.tagsStore.tags().length === 0) this.tagsStore.load();
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
