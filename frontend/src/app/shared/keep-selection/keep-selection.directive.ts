import { Directive } from '@angular/core';

/** A mouse press on this element leaves the reader's text selection standing. */
@Directive({
  selector: '[appKeepSelection]',
  host: { '(mousedown)': '$event.preventDefault()' },
})
export class KeepSelectionDirective {}
