import { Injectable, signal } from '@angular/core';

const KEY = 'sfr.sidebarHidden';
const HIDDEN = '1';

/** Whether the wide-layout sidebar column is collapsed, persisted per device.
 *  Independent of the narrow-layout drawer: below the drawer breakpoint the
 *  shell drives the sidebar from its own `sidebarOpen` state, not this. */
@Injectable({ providedIn: 'root' })
export class SidebarVisibilityService {
  readonly hidden = signal<boolean>(localStorage.getItem(KEY) === HIDDEN);

  hide(): void {
    localStorage.setItem(KEY, HIDDEN);
    this.hidden.set(true);
  }

  show(): void {
    localStorage.removeItem(KEY);
    this.hidden.set(false);
  }
}
