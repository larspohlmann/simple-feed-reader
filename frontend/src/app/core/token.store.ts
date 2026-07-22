// src/app/core/token.store.ts
import { Injectable, computed, signal } from '@angular/core';

const KEY = 'sfr.jwt';

@Injectable({ providedIn: 'root' })
export class TokenStore {
  private readonly _token = signal<string | null>(localStorage.getItem(KEY));
  readonly token = this._token.asReadonly();
  readonly isAuthenticated = computed(() => this._token() !== null);

  set(jwt: string): void {
    localStorage.setItem(KEY, jwt);
    this._token.set(jwt);
  }

  clear(): void {
    localStorage.removeItem(KEY);
    this._token.set(null);
  }
}
