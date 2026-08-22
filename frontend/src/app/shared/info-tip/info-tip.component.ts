import { ChangeDetectionStrategy, Component, OnDestroy, input, signal } from '@angular/core';
import { DismissOnOutsideDirective } from '../dismiss-on-outside.directive';
import { IconComponent } from '../icon/icon.component';

let nextId = 0;

/**
 * The one info affordance (#372): a small ⓘ button that toggles an
 * explanation panel. Click-to-toggle, never hover: hover does not exist on
 * touch.
 *
 * The panel floats as a popover (#541), so opening the tip never shifts the
 * sibling layout. This reverses the earlier in-flow arrangement (#433/#372),
 * where host and wrapper were `display: contents` and the panel claimed a
 * full-width line below the row. That choice avoided viewport-collision
 * handling on phones; #541 does the collision handling instead: the panel is
 * `position: fixed`, and on open its top/left are computed from the trigger's
 * rect and clamped to the viewport, so it never clips off either edge no matter
 * where the trigger sits on the line — the case a pure-CSS left/right anchor
 * could not cover on a narrow screen.
 *
 * Only one tip is open at a time: opening one closes any other. A module-level
 * reference to the currently open instance carries this — it is a plain field,
 * not a listener, so nothing leaks; `close` and `ngOnDestroy` null it.
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
export class InfoTipComponent implements OnDestroy {
  /** The tip whose panel is open, or null. Only one is ever open at a time. */
  private static openTip: InfoTipComponent | null = null;

  readonly text = input.required<string>();
  readonly label = input.required<string>();

  readonly open = signal(false);

  /** The fixed-position panel geometry, computed from the trigger on open and
   *  clamped so the panel stays fully within the viewport. */
  protected readonly panelTop = signal(0);
  protected readonly panelLeft = signal(0);
  protected readonly panelMaxWidth = signal(0);

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
    if (this.open()) {
      this.close();
      return;
    }
    this.reveal(event.currentTarget as HTMLElement | null);
  }

  /** Opens this tip and closes any other that is open, so only one shows. */
  private reveal(trigger: HTMLElement | null): void {
    InfoTipComponent.openTip?.close();
    InfoTipComponent.openTip = this;
    if (trigger) {
      this.positionAgainst(trigger);
    }
    this.open.set(true);
  }

  /**
   * Places the panel just below the trigger and clamps it to the viewport: the
   * width is capped to the screen, and the left edge is pulled back from either
   * margin so the panel never clips — on a phone the trigger can sit anywhere
   * on the line, which a fixed left- or right-anchor could not handle.
   */
  private positionAgainst(trigger: HTMLElement): void {
    const gutter = 8;
    const gap = 6;
    const rect = trigger.getBoundingClientRect();
    const maxWidth = Math.min(320, window.innerWidth - gutter * 2);
    const maxLeft = window.innerWidth - maxWidth - gutter;
    this.panelMaxWidth.set(maxWidth);
    this.panelLeft.set(Math.round(Math.max(gutter, Math.min(rect.left, maxLeft))));
    this.panelTop.set(Math.round(rect.bottom + gap));
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
    if (InfoTipComponent.openTip === this) {
      InfoTipComponent.openTip = null;
    }
    this.open.set(false);
  }

  ngOnDestroy(): void {
    if (InfoTipComponent.openTip === this) {
      InfoTipComponent.openTip = null;
    }
  }
}
