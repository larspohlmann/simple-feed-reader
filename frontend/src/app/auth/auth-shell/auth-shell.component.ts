// src/app/auth/auth-shell/auth-shell.component.ts
import { Component, Input } from '@angular/core';

@Component({
  selector: 'app-auth-shell',
  template: `
    <main>
      <section class="card">
        <h1>{{ title }}</h1>
        @if (subtitle) {
          <p class="sub">{{ subtitle }}</p>
        }
        <ng-content />
      </section>
    </main>
  `,
  styles: [
    `
      main {
        min-height: 100dvh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: var(--space-4);
        background: var(--surface-0);
      }
      .card {
        width: 100%;
        max-width: 380px;
        background: var(--surface-2);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: var(--space-6);
      }
      h1 {
        font-size: var(--fs-xl);
        margin-bottom: var(--space-2);
      }
      .sub {
        color: var(--text-muted);
        font-size: var(--fs-sm);
        margin-bottom: var(--space-5);
      }
    `,
  ],
})
export class AuthShellComponent {
  @Input({ required: true }) title!: string;
  @Input() subtitle: string | null = null;
}
