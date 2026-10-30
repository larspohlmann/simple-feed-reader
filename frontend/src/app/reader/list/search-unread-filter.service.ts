import { Injectable, signal } from '@angular/core';

/** The All posts / Only unread switch inside a direct search. Never the account's
 *  preference: a search hunts for a post, often one already read, so every
 *  search opens on All posts and a flip lasts until the search ends (#1922). */
@Injectable({ providedIn: 'root' })
export class SearchUnreadFilter {
  private readonly chosen = signal(false);

  readonly unreadOnly = this.chosen.asReadonly();

  set(unreadOnly: boolean): void {
    this.chosen.set(unreadOnly);
  }

  reset(): void {
    this.chosen.set(false);
  }
}
