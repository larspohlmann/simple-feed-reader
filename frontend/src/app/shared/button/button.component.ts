import { Component, Input } from '@angular/core';
import { SpinnerComponent } from '../spinner/spinner.component';

@Component({
  selector: 'app-button',
  imports: [SpinnerComponent],
  template: `
    <button [type]="type" [disabled]="loading || disabled" [class.primary]="variant === 'primary'">
      @if (loading) {
        <app-spinner [size]="16" />
      } @else {
        <ng-content />
      }
    </button>
  `,
  styles: [
    `
      button {
        height: var(--control-h);
        padding: 0 var(--space-4);
        border-radius: var(--radius);
        border: 1px solid var(--border-strong);
        background: var(--surface-1);
        color: var(--text-primary);
        cursor: pointer;
        width: 100%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
      }
      button.primary {
        background: var(--accent);
        color: var(--on-accent);
        border-color: var(--accent);
      }
      button:disabled {
        cursor: default;
        opacity: 0.7;
      }
    `,
  ],
})
export class ButtonComponent {
  @Input() type: 'button' | 'submit' = 'button';
  @Input() variant: 'default' | 'primary' = 'default';
  @Input() loading = false;
  @Input() disabled = false;
}
