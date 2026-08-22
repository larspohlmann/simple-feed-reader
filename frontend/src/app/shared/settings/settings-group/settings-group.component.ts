import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { IconComponent } from '../../icon/icon.component';

/**
 * One grouped-settings section: a header (a tinted icon chip, a title and an
 * optional caption) above a card surface that projects the group's rows or
 * disclosures. The Grouped layout of the AI-settings redesign (#541) stacks
 * several of these; extracting the header + panel shell keeps every group's
 * chrome identical.
 *
 * `icon`, `title` and `caption` take already-resolved strings, not i18n keys --
 * this component lives in `shared/` and must not hardcode a feature's
 * translation keys. `icon` is a Material Symbol name rendered through
 * `<app-icon>`. `caption` is optional: the caption element is omitted when it
 * is empty.
 */
@Component({
  selector: 'app-settings-group',
  imports: [IconComponent],
  templateUrl: './settings-group.component.html',
  styleUrl: './settings-group.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SettingsGroupComponent {
  readonly icon = input<string>('');
  readonly title = input<string>('');
  readonly caption = input<string>('');
}
