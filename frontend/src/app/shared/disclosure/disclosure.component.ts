// src/app/shared/disclosure/disclosure.component.ts
import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';

/**
 * The one wrapper for a native `<details>`/`<summary>` collapsed-content
 * pattern: a summary line and the projected body. No open/closed signal, no
 * animation, no ARIA reimplementation -- `<details>` already gives all three
 * for free. Extracted in #321 from the two places this shape had been
 * hand-rolled (`recommendation-settings-card`'s fixed-prompt panel,
 * `recommendation-debug-log`'s panel shell); a third occurrence was about to
 * land in #321 Task 8.
 *
 * `label` takes an already-translated string, not an i18n key -- this
 * component lives in `shared/` and must not hardcode a feature's translation
 * keys. It is optional: a caller that needs a richer summary line projects
 * its own markup into the `[summary]` slot instead and leaves `label` unset.
 *
 * `appearance` picks the summary chrome: `'pill'` (default) is the bordered
 * toggle button this component always rendered; `'row'` is a flat, full-width
 * list row for callers that render one `<app-disclosure>` per item in a list;
 * `'card-header'` is a flat, full-width heading with no horizontal padding, so
 * it aligns to a card's content box when it wraps a card body (see
 * `appearance="drill-in"`) rather than inheriting the row's own inset;
 * `'drill-in'` is a full-width Grouped-list row with the heading
 * (and optional description) on the left and a trailing chevron, for an
 * advanced section that expands in place (see the Expert-settings panel).
 */
@Component({
  selector: 'app-disclosure',
  templateUrl: './disclosure.component.html',
  styleUrl: './disclosure.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DisclosureComponent {
  readonly label = input<string>('');
  readonly appearance = input<'pill' | 'row' | 'card-header' | 'drill-in'>('pill');

  /**
   * One-way: the caller's own state decides whether `<details>` starts open,
   * this component never reports its open state back. Bound as `[open]` on
   * the native element, so Angular only ever *writes* it when the bound
   * expression's value changes -- a reader who closes (or opens) the
   * `<details>` by hand is not fought back on the next unrelated change
   * detection pass, only when the caller's own value actually flips.
   */
  readonly startOpen = input(false);

  /**
   * Announced when the body is revealed, and only then. A caller that loads
   * its content lazily needs this because `<details>`'s own `toggle` event
   * does not bubble, so it cannot be listened for on this component's host.
   * Closing is deliberately silent: nobody has to undo a fetch.
   */
  readonly opened = output<void>();

  onToggle(details: HTMLDetailsElement): void {
    if (details.open) this.opened.emit();
  }
}
