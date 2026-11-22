import { DOCUMENT } from '@angular/common';
import { Injectable, inject } from '@angular/core';

export const PAGE_SCROLLBAR_HIDDEN_CLASS = 'page-scrollbar-hidden';

@Injectable({ providedIn: 'root' })
export class PageScrollbar {
  private readonly root = inject(DOCUMENT).documentElement;
  private readonly claims = new Set<object>();

  hide(): () => void {
    const claim = {};
    if (this.claims.size === 0) this.root.classList.add(PAGE_SCROLLBAR_HIDDEN_CLASS);
    this.claims.add(claim);
    return () => {
      if (!this.claims.delete(claim) || this.claims.size > 0) return;
      this.root.classList.remove(PAGE_SCROLLBAR_HIDDEN_CLASS);
    };
  }
}
