import { DOCUMENT } from '@angular/common';
import { Injectable, inject } from '@angular/core';

/** On `<html>` while anything holds the page. Not CDK's block strategy: its fixed `<html>`
 *  shrinks the page to the viewport, which expands Brave's collapsed toolbar (#2088, #2100). */
export const PAGE_HELD_CLASS = 'page-held';

@Injectable({ providedIn: 'root' })
export class PageHold {
  private readonly root = inject(DOCUMENT).documentElement;
  private readonly holders = new Set<object>();

  /** Stops the page scrolling where it stands, keeping its height; returns the release. */
  hold(): () => void {
    const holder = {};
    if (this.holders.size === 0) this.root.classList.add(PAGE_HELD_CLASS);
    this.holders.add(holder);
    return () => {
      if (!this.holders.delete(holder) || this.holders.size > 0) return;
      this.root.classList.remove(PAGE_HELD_CLASS);
    };
  }
}
