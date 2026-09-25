import { Directive, input } from '@angular/core';

/** An entry-state toggle (favourite, keep, read); the look lives in `styles/_flag-toggle.scss`. */
@Directive({
  selector: 'button[appFlagToggle]',
  host: {
    class: 'flag-toggle',
    '[class.on]': 'appFlagToggle()',
    '[attr.aria-pressed]': 'appFlagToggle()',
  },
})
export class FlagToggleDirective {
  readonly appFlagToggle = input.required<boolean>();
}
