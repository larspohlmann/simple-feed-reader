// src/app/shared/disclosure/disclosure.component.ts
import { ChangeDetectionStrategy, Component, input } from '@angular/core';

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
 * list row for callers that render one `<app-disclosure>` per item in a list.
 */
@Component({
  selector: 'app-disclosure',
  templateUrl: './disclosure.component.html',
  styleUrl: './disclosure.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DisclosureComponent {
  readonly label = input<string>('');
  readonly appearance = input<'pill' | 'row'>('pill');
}
