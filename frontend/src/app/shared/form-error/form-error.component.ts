import { Component, Input } from '@angular/core';

@Component({
  selector: 'app-form-error',
  template: `@if (message) {
    <p class="err" role="alert">{{ message }}</p>
  }`,
  styles: [
    `
      .err {
        color: var(--danger);
        background: var(--bg-danger);
        border-radius: var(--radius);
        padding: var(--space-2) var(--space-3);
        font-size: var(--fs-sm);
      }
    `,
  ],
})
export class FormErrorComponent {
  @Input() message: string | null = null;
}
