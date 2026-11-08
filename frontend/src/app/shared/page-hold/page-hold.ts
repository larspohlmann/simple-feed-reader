import { DOCUMENT } from '@angular/common';
import { Injectable, inject } from '@angular/core';

/** On `<html>` while anything holds the page. Not CDK's block strategy: its fixed `<html>`
 *  shrinks the page to the viewport, which expands Brave's collapsed toolbar (#2088, #2100). */
export const PAGE_HELD_CLASS = 'page-held';

@Injectable({ providedIn: 'root' })
export class PageHold {
  private readonly root = inject(DOCUMENT).documentElement;
  private holders = 0;

  /** Stops the page scrolling where it stands, keeping its height; returns the release. */
  hold(): () => void {
    this.holders++;
    this.root.classList.add(PAGE_HELD_CLASS);
    let released = false;
    return () => {
      if (released) return;
      released = true;
      this.holders--;
      if (this.holders === 0) this.root.classList.remove(PAGE_HELD_CLASS);
    };
  }
}
