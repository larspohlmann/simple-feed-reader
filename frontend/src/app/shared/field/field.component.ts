import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { InfoTipComponent } from '../info-tip/info-tip.component';

/**
 * Form field layout: label, optional required marker, the projected control,
 * and an optional error.
 *
 * Deliberately not a ControlValueAccessor. The native control stays in the
 * consumer's template with its own formControlName, so `type`,
 * `autocomplete`, `inputmode` and the rest need no re-exposure as inputs; this
 * component owns only what was being retyped — the label, the rhythm and the
 * error slot. The projected control is styled globally by styles/_controls.scss
 * because ViewEncapsulation does not reach projected content.
 */
@Component({
  selector: 'app-field',
  imports: [InfoTipComponent],
  templateUrl: './field.component.html',
  styleUrl: './field.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FieldComponent {
  readonly label = input.required<string>();
  readonly error = input<string | null>(null);
  readonly hint = input<string | null>(null);
  /** Already-translated explanation; renders an `<app-info-tip>` at the end of
   *  the label row (#372), whose panel opens as a fixed popover clamped to the
   *  viewport (#541), never shifting sibling layout. */
  readonly info = input<string | null>(null);
  readonly required = input(false);
}
