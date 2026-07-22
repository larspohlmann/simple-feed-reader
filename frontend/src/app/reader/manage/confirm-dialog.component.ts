// src/app/reader/manage/confirm-dialog.component.ts
import { Component, inject } from '@angular/core';
import { A11yModule } from '@angular/cdk/a11y';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';

export interface ConfirmData {
  title: string;
  message: string;
  confirmLabel: string;
  danger?: boolean;
}

@Component({
  selector: 'app-confirm-dialog',
  imports: [A11yModule],
  template: `
    <div class="dialog" role="alertdialog" aria-modal="true" [attr.aria-label]="data.title" cdkTrapFocus>
      <h2>{{ data.title }}</h2>
      <p class="msg">{{ data.message }}</p>
      <div class="row">
        <button type="button" (click)="ref.close(false)">Cancel</button>
        <button
          type="button"
          [class.primary]="!data.danger"
          [class.danger]="data.danger"
          cdkFocusInitial
          (click)="ref.close(true)"
        >
          {{ data.confirmLabel }}
        </button>
      </div>
    </div>
  `,
  styles: [
    `
      .dialog {
        background: var(--surface-2);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: var(--space-5);
        width: min(400px, 92vw);
        display: flex;
        flex-direction: column;
        gap: var(--space-3);
      }
      h2 {
        margin: 0;
        font-size: var(--fs-lg);
      }
      .msg {
        margin: 0;
        color: var(--text-secondary);
      }
      .row {
        display: flex;
        justify-content: flex-end;
        gap: var(--space-2);
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
      .row button.danger {
        background: var(--danger);
        color: var(--on-accent);
        border-color: var(--danger);
      }
    `,
  ],
})
export class ConfirmDialogComponent {
  readonly ref = inject<DialogRef<boolean>>(DialogRef);
  readonly data = inject<ConfirmData>(DIALOG_DATA);
}
