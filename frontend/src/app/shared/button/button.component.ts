// src/app/shared/button/button.component.ts
import { booleanAttribute, ChangeDetectionStrategy, Component, input } from '@angular/core';
import { SpinnerComponent } from '../spinner/spinner.component';

/**
 * `danger` confirms a destructive action, `danger-outline` initiates one: the
 * filled weight belongs to the moment of destruction (a confirm dialog's
 * confirm), the outlined one to the row action that merely opens that dialog.
 * Both existed before the component absorbed them; flattening them would have
 * made every Delete in a list shout as loudly as the confirmation.
 *
 * `accent-outline` is the same pairing on the accent: it marks an action as
 * live without taking the accent fill, which belongs to the one action a
 * surface exists for. A settings card whose save bar already owns `primary`
 * can still show that a secondary action is ready to be used.
 */
export type ButtonVariant =
  'default' | 'primary' | 'accent-outline' | 'danger' | 'danger-outline' | 'ghost';
export type ButtonSize = 'sm' | 'md';

/**
 * The app's one ordinary button: a label, optionally a leading icon, one of
 * five weights. Icon-only affordances that carry their own interaction
 * semantics -- the sidebar's row menus, the entry row's read toggles, the
 * view-controls segmented control -- deliberately stay out; forcing them
 * through here would turn this into a grab bag.
 *
 * Full width is opt-in (`block`). It used to be unconditional, which is why no
 * surface outside the auth forms could adopt this component.
 */
@Component({
  selector: 'app-button',
  imports: [SpinnerComponent],
  templateUrl: './button.component.html',
  styleUrl: './button.component.scss',
  host: { '[class.block]': 'block()' },
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ButtonComponent {
  readonly type = input<'button' | 'submit'>('button');
  readonly variant = input<ButtonVariant>('default');
  readonly size = input<ButtonSize>('md');
  readonly loading = input(false);
  readonly disabled = input(false);

  /**
   * Accessible name for the real `<button>`. Only needed when the visible label
   * is hidden — an action that collapses to its leading icon on narrow screens
   * still has to announce itself. A `<button>` carries the `button` role, so
   * `aria-label` names it reliably here (unlike a bare generic node).
   */
  readonly ariaLabel = input<string>();

  /** Stretch to the container's width instead of hugging the label. */
  readonly block = input(false, { transform: booleanAttribute });

  /**
   * Marks the real `<button>` as the dialog's initial focus target. The CDK's
   * focus trap looks for a `cdkFocusInitial` element and calls `focus()` on it,
   * and this component's host is not focusable -- put the attribute on the host
   * and the dialog opens with nothing focused.
   */
  readonly focusInitial = input(false, { transform: booleanAttribute });
}
