// src/app/shared/settings-card/settings-card.component.ts
import { NgTemplateOutlet } from '@angular/common';
import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { DisclosureComponent } from '../disclosure/disclosure.component';

/**
 * The one surface a settings or admin section sits in: a heading, an optional
 * description, and the section's own content. Extracted in #180 Phase 4, when
 * five different card/panel treatments had accumulated across seven
 * stylesheets -- see docs/design-language.md.
 *
 * A card wraps a *section*, not a row. Rows stay plain rows inside one card;
 * the tags list used to give each row its own border and read as nested cards.
 *
 * `heading` and `description` take already-translated strings rather than i18n
 * keys, so this shared component never hardcodes a feature's translation keys
 * -- the caller resolves those with its own `transloco` pipe.
 *
 * `collapsible` turns the heading into a collapsed `<app-disclosure>` toggle
 * (the one shared `<details>`/`<summary>` wrapper): the body -- the description
 * and the projected content -- is collapsed by default and opens on click.
 * Collapsible cards do not support the `cardActions` slot.
 */
@Component({
  selector: 'app-settings-card',
  imports: [NgTemplateOutlet, DisclosureComponent],
  templateUrl: './settings-card.component.html',
  styleUrl: './settings-card.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SettingsCardComponent {
  readonly heading = input.required<string>();

  /** `null` renders the card with no description line. */
  readonly description = input<string | null>(null);

  /** `true` renders the heading as a collapsed `<details>` toggle. */
  readonly collapsible = input<boolean>(false);
}
