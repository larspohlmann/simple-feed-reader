import { ChangeDetectionStrategy, Component, input } from '@angular/core';

/**
 * One settings row: a title and an optional description stacked on the left,
 * a projected control on the right, vertically centred. It is the primitive a
 * settings group stacks; the inset hairline divider between rows is drawn here
 * on `:host(:not(:last-child))`, so the parent group only supplies the box.
 *
 * `title` and `description` take already-translated strings, not i18n keys --
 * this component lives in `shared/` and must not hardcode a feature's
 * translation keys. `description` is optional: an empty string omits the
 * `.row-desc` element entirely.
 *
 * `stackable` marks a row whose control (a select or number input) may take the
 * full width on a narrow viewport: the row wraps and the control slot widens to
 * the full line. The control itself must be widened by the component that
 * declares it -- projected content carries that component's style scope, not
 * this one's -- so a stackable row alone does not stretch the field.
 *
 * The control projects into the default `<ng-content>` slot. An info-tip
 * trigger for the title projects into the named `[rowTitleTip]` slot, which
 * renders immediately after the title text inside `.row-title`.
 */
@Component({
  selector: 'app-settings-row',
  templateUrl: './settings-row.component.html',
  styleUrl: './settings-row.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SettingsRowComponent {
  readonly title = input<string>('');
  readonly description = input<string>('');
  readonly stackable = input(false);
}
