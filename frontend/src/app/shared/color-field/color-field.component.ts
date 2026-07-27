// src/app/shared/color-field/color-field.component.ts
import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { TAG_COLORS } from '../icon-choices';

/**
 * Colour chooser: a row of presets, a native picker for anything else, and a
 * clear button for "no colour". Lifted out of the tag dialog so the admin
 * catalog's category colour is chosen the same way.
 *
 * The presets come from shared/icon-choices, which already held them — this
 * component is a move, not a redesign, and that module stays the single place
 * the palette is defined.
 *
 * Not a ControlValueAccessor: both consumers drive it from a signal rather than
 * a form control, and value/valueChange keeps it usable with either.
 */
@Component({
  selector: 'app-color-field',
  imports: [TranslocoPipe],
  templateUrl: './color-field.component.html',
  styleUrl: './color-field.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ColorFieldComponent {
  readonly value = input<string | null>(null);
  readonly valueChange = output<string | null>();

  /**
   * "No colour" is a valid tag, but a catalog category always has one, so the
   * clear button is opt-out rather than unconditional.
   */
  readonly clearable = input(true);

  protected readonly presets = TAG_COLORS;

  /** A colour input has no "unset" state, so it needs a concrete default. */
  protected readonly fallback = '#3f8676';

  protected pickedValue(event: Event): string {
    return (event.target as HTMLInputElement).value;
  }

  protected pick(color: string): void {
    this.valueChange.emit(color);
  }

  protected clear(): void {
    this.valueChange.emit(null);
  }
}
