// src/app/core/token.store.spec.ts
import { TestBed } from '@angular/core/testing';
import { TokenStore } from './token.store';

describe('TokenStore', () => {
  beforeEach(() => localStorage.clear());

  it('starts unauthenticated with no stored token', () => {
    const store = TestBed.inject(TokenStore);
    expect(store.token()).toBeNull();
    expect(store.isAuthenticated()).toBe(false);
  });

  it('persists and exposes a set token, and clears it', () => {
    const store = TestBed.inject(TokenStore);
    store.set('jwt-123');
    expect(store.token()).toBe('jwt-123');
    expect(store.isAuthenticated()).toBe(true);
    expect(localStorage.getItem('sfr.jwt')).toBe('jwt-123');
    store.clear();
    expect(store.token()).toBeNull();
    expect(localStorage.getItem('sfr.jwt')).toBeNull();
  });

  it('rehydrates from localStorage on construction', () => {
    localStorage.setItem('sfr.jwt', 'persisted');
    const store = TestBed.inject(TokenStore);
    expect(store.token()).toBe('persisted');
  });
});
