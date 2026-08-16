import { ChangeDetectionStrategy, Component, input, signal } from '@angular/core';
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
 * The component contributes no box of its own (#433). Host and wrapper are
 * `display: contents`, so the consumer's row lays out the trigger and the
 * panel as its own children and the panel can take a full-width line beneath
 * that row. The earlier arrangement — a boxed wrapper holding both, plus a
 * `corner` mode that absolutely positioned the trigger away from its panel —
 * made the panel one flex item beside the label in a row, and in `app-field`
 * opened it under the control rather than under the ⓘ that was clicked.
 * Neither matched the in-flow contract this component documents.
 *
 * `text` and `label` take already-translated strings, not i18n keys — this
 * component lives in `shared/` and must not hardcode a feature's translation
 * keys. `label` names the trigger for assistive tech; callers pass the label
 * of the control the tip explains, and `aria-expanded` tells it apart from
 * the control itself.
 */
@Component({
  selector: 'app-info-tip',
  imports: [DismissOnOutsideDirective, IconComponent],
  templateUrl: './info-tip.component.html',
  styleUrl: './info-tip.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class InfoTipComponent {
  readonly text = input.required<string>();
  readonly label = input.required<string>();

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
    this.swallow(event);
    this.open.update((value) => !value);
  }

  /**
   * The panel needs the trigger's guard too, now that it sits inside the row
   * it explains: `app-field` renders one inside the `<label>` that wraps the
   * control, where a click reaching the label would toggle that control.
   */
  swallow(event: Event): void {
    event.preventDefault();
    event.stopPropagation();
  }

  close(): void {
    this.open.set(false);
  }
}
