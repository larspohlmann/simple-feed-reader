import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';

/** A checkable row of a role=menu: it toggles in place and leaves the menu open. */
@Component({
  selector: 'app-menu-checkbox',
  templateUrl: './menu-checkbox.component.html',
  styleUrl: './menu-checkbox.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class MenuCheckboxComponent {
  readonly label = input.required<string>();
  readonly checked = input(false);
  readonly toggled = output<void>();
}
