import { ChangeDetectionStrategy, Component } from '@angular/core';

/**
 * The vertical rhythm of a settings or admin page: a column that stacks
 * `<app-settings-group>`s with the one canonical gap between them.
 *
 * The gap belongs here, on a flex container, rather than in a global adjacent-
 * sibling rule. `app-settings-card + app-settings-card` in `_base.scss` was
 * such a rule, and it stopped firing the moment one card was rendered from
 * inside another component -- the child then had to carry a compensating
 * margin (#454). A stack's children are flex items, so a child that happens to
 * be another component's host element is spaced identically to a group written
 * inline, and no consumer ever compensates.
 *
 * `min-width: 0` is on the host because it is a flex item of the settings
 * shell's own column, and a flex item's `min-width` defaults to `auto` -- which
 * refuses to shrink below its content's intrinsic width. Without it a wide
 * descendant widens the whole page instead of scrolling inside its own
 * container (#409).
 */
@Component({
  selector: 'app-settings-stack',
  templateUrl: './settings-stack.component.html',
  styleUrl: './settings-stack.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SettingsStackComponent {}
