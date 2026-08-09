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
 * keys.
 */
@Component({
  selector: 'app-disclosure',
  templateUrl: './disclosure.component.html',
  styleUrl: './disclosure.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DisclosureComponent {
  readonly label = input.required<string>();
}
