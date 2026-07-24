import { Component, inject } from '@angular/core';
import { IconComponent } from '../../shared/icon/icon.component';
import { ThemeService } from '../../theme/theme.service';
import { ThemeMode } from '../../theme/themes/registry';
import { ReadingLayoutService } from '../reading-layout.service';

/**
 * The reading-layout and theme segmented controls. They live in the sidebar so
 * the top bar has room for the article's back button and reader-mode switch.
 */
@Component({
  selector: 'app-view-controls',
  imports: [IconComponent],
  template: `
    <div class="seg" role="group" aria-label="Reading layout">
      <button
        aria-label="Magazine layout"
        [class.active]="layout.mode() === 'magazine'"
        (click)="layout.set('magazine')"
      >
        <app-icon name="view_quilt" [size]="18" />
      </button>
      <button
        aria-label="List layout"
        [class.active]="layout.mode() === 'list'"
        (click)="layout.set('list')"
      >
        <app-icon name="view_agenda" [size]="18" />
      </button>
      <button
        class="pane"
        aria-label="Pane layout"
        [class.active]="layout.mode() === 'pane'"
        (click)="layout.set('pane')"
      >
        <app-icon name="view_column_2" [size]="18" />
      </button>
    </div>

    <div class="seg" role="group" aria-label="Theme">
      @for (m of modes; track m.id) {
        <button
          [class.active]="theme.mode() === m.id"
          [attr.aria-pressed]="theme.mode() === m.id"
          [title]="m.label"
          (click)="theme.setMode(m.id)"
        >
          <app-icon [name]="m.icon" [size]="18" />
        </button>
      }
    </div>
  `,
  styles: `
    :host {
      display: flex;
      gap: var(--space-2);
    }
    .seg {
      display: inline-flex;
      flex: 1;
      border: 1px solid var(--border);
      border-radius: var(--radius);
      overflow: hidden;
    }
    .seg button {
      flex: 1;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: var(--space-2);
      background: var(--surface-2);
      border: none;
      color: var(--text-secondary);
      cursor: pointer;
    }
    .seg button:hover {
      background: var(--surface-0);
    }
    .seg button.active {
      background: var(--accent-soft);
      color: var(--accent);
    }
    /* Pane layout needs a wide viewport, so hide it on narrow screens. */
    @media (max-width: 720px) {
      .seg button.pane {
        display: none;
      }
    }
  `,
})
export class ViewControlsComponent {
  readonly theme = inject(ThemeService);
  readonly layout = inject(ReadingLayoutService);

  readonly modes: { id: ThemeMode; label: string; icon: string }[] = [
    { id: 'light', label: 'Light', icon: 'light_mode' },
    { id: 'dark', label: 'Dark', icon: 'dark_mode' },
    { id: 'system', label: 'System', icon: 'contrast' },
  ];
}
