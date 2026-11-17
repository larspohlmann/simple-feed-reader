import { Directive, ElementRef, inject } from '@angular/core';

/**
 * Shows an ellipsised element's full text as a native tooltip, and only while it is cut off.
 * Measured on hover, so it follows sidebar resizes without observing anything.
 */
@Directive({
  selector: '[appTruncationTitle]',
  host: { '(mouseenter)': 'syncTitle()' },
})
export class TruncationTitleDirective {
  private readonly host: HTMLElement = inject(ElementRef).nativeElement;

  protected syncTitle(): void {
    if (this.host.scrollWidth > this.host.clientWidth) {
      this.host.title = this.host.textContent?.trim() ?? '';
      return;
    }
    this.host.removeAttribute('title');
  }
}
