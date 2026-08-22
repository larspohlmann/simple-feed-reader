import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { ButtonComponent } from '../../button/button.component';

/**
 * The shared save/reset affordance for a settings surface: an "unsaved changes"
 * indicator, a ghost Reset button and a primary Save button. Save is disabled
 * until there are changes to save, and shows a spinner (via the shared button's
 * own `loading` state) while a persist is in flight.
 *
 * This bar owns only the dirty/save/reset affordance. It deliberately does NOT
 * own the success confirmation: the consumer decides when a persist succeeded
 * and fires the global `shared/toast` itself. Coupling the toast in here would
 * make the bar guess at an outcome it never sees.
 *
 * The visible labels arrive as already-translated string inputs, not i18n keys.
 * This component lives in `shared/` and must not reach for a feature's
 * translation keys, so the consumer passes the translated `saveLabel`,
 * `resetLabel` and `unsavedLabel` in (see Task 15).
 */
@Component({
  selector: 'app-settings-save-bar',
  imports: [ButtonComponent],
  templateUrl: './save-bar.component.html',
  styleUrl: './save-bar.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SettingsSaveBarComponent {
  readonly dirty = input(false);
  readonly saving = input(false);

  readonly saveLabel = input('');
  readonly resetLabel = input('');
  readonly unsavedLabel = input('');

  readonly save = output<void>();
  // The public interface fixes this output's name as `reset`; it shares a name
  // with the native form-reset event but this host is a plain element, so there
  // is no real DOM event to shadow.
  // eslint-disable-next-line @angular-eslint/no-output-native
  readonly reset = output<void>();

  onSave(): void {
    if (this.dirty() && !this.saving()) this.save.emit();
  }

  onReset(): void {
    this.reset.emit();
  }
}
