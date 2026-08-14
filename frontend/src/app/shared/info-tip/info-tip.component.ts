import { booleanAttribute, ChangeDetectionStrategy, Component, input, signal } from '@angular/core';
import { DismissOnOutsideDirective } from '../dismiss-on-outside.directive';
import { IconComponent } from '../icon/icon.component';

let nextId = 0;

/**
 * The one info affordance (#372): a small ⓘ button that toggles an
 * explanation panel. The panel renders in normal flow and pushes content
 * down rather than floating — a floating popover needs viewport-collision
 * handling on phones, while an in-flow panel cannot clip or overflow by
 * construction. Click-to-toggle, never hover: hover does not exist on touch.
 *
 * `text` and `label` take already-translated strings, not i18n keys — this
 * component lives in `shared/` and must not hardcode a feature's translation
 * keys. `label` names the trigger for assistive tech; callers pass the label
 * of the control the tip explains, and `aria-expanded` tells it apart from
 * the control itself.
 *
 * `corner` places the trigger at the top-right of the nearest *positioned*
 * ancestor — `<app-field>` sets `position: relative` on its host and uses
 * this to put the ⓘ in its label row while the panel stays in the field's
 * flow. The host itself must stay unpositioned in corner mode for that
 * anchoring to work, which is why the styles never give it `position`.
 * A consumer that turns this on owes the label row the height of a full tap
 * target, or the coarse-pointer trigger reaches down over the control — see
 * `app-field`'s `.has-info` rule.
 *
 * The styles key on the host *class*, which this component binds from the
 * input, not on the `corner` attribute: an attribute is only present for the
 * static `corner` form, so `[corner]="true"` would have set the input and
 * anchored nothing.
 */
@Component({
  selector: 'app-info-tip',
  imports: [DismissOnOutsideDirective, IconComponent],
  templateUrl: './info-tip.component.html',
  styleUrl: './info-tip.component.scss',
  host: { '[class.corner]': 'corner()' },
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class InfoTipComponent {
  readonly text = input.required<string>();
  readonly label = input.required<string>();
  readonly corner = input(false, { transform: booleanAttribute });

  readonly open = signal(false);

  /** Ties the trigger to its panel; unique so several tips can coexist. */
  protected readonly panelId = `info-tip-panel-${nextId++}`;

  /**
   * preventDefault + stopPropagation so a tip placed near a `<summary>` or a
   * `<label>` can never trigger the container's own activation — a click
   * that falls through would collapse the row or toggle the control the tip
   * is explaining.
   */
  toggle(event: Event): void {
    event.preventDefault();
    event.stopPropagation();
    this.open.update((value) => !value);
  }

  close(): void {
    this.open.set(false);
  }
}
