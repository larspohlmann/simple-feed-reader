// src/app/shared/toggle/toggle.component.ts
import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';

/**
 * A labelled on/off switch built on a native checkbox, so keyboard focus,
 * space-to-toggle and assistive-technology semantics come for free.
 */
@Component({
  selector: 'app-toggle',
  templateUrl: './toggle.component.html',
  styleUrl: './toggle.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ToggleComponent {
  readonly checked = input(false);
  readonly label = input.required<string>();
  /** Optional: lets a caller's own `<label for>` reach the native checkbox. */
  readonly inputId = input<string>();
  /** Blocks interaction -- e.g. a switch that only makes sense once its
   *  prerequisite config is saved (see the proxy enable toggle, #490). */
  readonly disabled = input(false);
  readonly toggled = output<boolean>();

  onChange(event: Event): void {
    this.toggled.emit((event.target as HTMLInputElement).checked);
  }
}
