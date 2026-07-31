// src/app/shared/settings-card/settings-card.component.ts
import { ChangeDetectionStrategy, Component, input } from '@angular/core';

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
 */
@Component({
  selector: 'app-settings-card',
  templateUrl: './settings-card.component.html',
  styleUrl: './settings-card.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SettingsCardComponent {
  readonly heading = input.required<string>();

  /** `null` renders the card with no description line. */
  readonly description = input<string | null>(null);
}
