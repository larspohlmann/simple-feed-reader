import { Component, Input } from '@angular/core';

@Component({
  selector: 'app-spinner',
  templateUrl: './spinner.component.html',
  styleUrl: './spinner.component.scss',
})
export class SpinnerComponent {
  @Input() size = 18;
  /** Decorative use (e.g. the app brand mark) hides the spinner from the
   *  accessibility tree; the default keeps the "Loading" status role. */
  @Input() decorative = false;
  /** When false the mark holds still in the signal colour instead of animating.
   *  Loading spinners leave this on; the brand mark only animates while a
   *  refresh is running. */
  @Input() animate = true;
}
