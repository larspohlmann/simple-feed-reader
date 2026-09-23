import { Injectable, signal } from '@angular/core';

const KEY = 'sfr.unread-only';

@Injectable({ providedIn: 'root' })
export class UnreadFilterService {
  readonly unreadOnly = signal<boolean>(localStorage.getItem(KEY) === '1');

  set(unreadOnly: boolean): void {
    localStorage.setItem(KEY, unreadOnly ? '1' : '0');
    this.unreadOnly.set(unreadOnly);
  }
}
