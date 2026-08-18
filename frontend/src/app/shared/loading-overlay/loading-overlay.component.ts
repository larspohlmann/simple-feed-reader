import { Component, input } from '@angular/core';
import { SpinnerComponent } from '../spinner/spinner.component';

/**
 * A centred spinner-on-a-card laid over its container while something loads.
 *
 * Decorative by default: the busy region it covers should carry `aria-busy`,
 * so a second "Loading" announcement here would only be noise (#254). Its host
 * is the veil and stays in the DOM; `shown` fades it, so the fade plays on the
 * way out as well as in — a structural removal would cut the exit frame.
 *
 * The host fills its nearest positioned ancestor. A consumer that must leave a
 * sticky header uncovered sets `--loading-overlay-inset` (the feed list does).
 */
@Component({
  selector: 'app-loading-overlay',
  imports: [SpinnerComponent],
  templateUrl: './loading-overlay.component.html',
  styleUrl: './loading-overlay.component.scss',
  host: { '[class.shown]': 'shown()', 'aria-hidden': 'true' },
})
export class LoadingOverlayComponent {
  /** Fades the veil in; false fades it back out (kept in the DOM meanwhile). */
  readonly shown = input(false);
  /** Already-translated caption above the spinner; empty renders none. */
  readonly label = input('');
  /** Spinner glyph size in px. */
  readonly spinnerSize = input(96);
}
