import { DestroyRef, Injectable, inject, signal } from '@angular/core';
import { NavigationEnd, Router } from '@angular/router';
import { filter } from 'rxjs';

const READER_URL_KEY = 'sfr.reader-location.current';
const SIGN_IN_RETURN_URL_KEY = 'sfr.reader-location.sign-in-return';

@Injectable({ providedIn: 'root' })
export class ReaderLocationService {
  private readonly router = inject(Router);
  private readonly destroyRef = inject(DestroyRef);
  private readonly currentReaderUrl = signal(this.readReaderUrl(READER_URL_KEY));
  private readonly signInReturnUrl = signal(this.readReaderUrl(SIGN_IN_RETURN_URL_KEY));

  constructor() {
    const subscription = this.router.events
      .pipe(filter((event): event is NavigationEnd => event instanceof NavigationEnd))
      .subscribe((event) => this.rememberReaderUrl(event.urlAfterRedirects));
    this.destroyRef.onDestroy(() => subscription.unsubscribe());
  }

  savedReaderUrl(): string {
    return this.currentReaderUrl() ?? '/';
  }

  rememberAttemptedReaderUrl(url: string): void {
    if (!isReaderUrl(url)) return;
    this.rememberReaderUrl(url);
    this.setSignInReturnUrl(url);
  }

  rememberSavedReaderUrlForSignIn(): void {
    this.setSignInReturnUrl(this.savedReaderUrl());
  }

  consumeSignInReturnUrl(): string {
    const url = this.signInReturnUrl() ?? '/';
    this.clearSignInReturnUrl();
    return url;
  }

  clearSignInReturnUrl(): void {
    this.signInReturnUrl.set(null);
    this.remove(SIGN_IN_RETURN_URL_KEY);
  }

  private rememberReaderUrl(url: string): void {
    if (!isReaderUrl(url)) return;
    this.currentReaderUrl.set(url);
    this.write(READER_URL_KEY, url);
  }

  private setSignInReturnUrl(url: string): void {
    this.signInReturnUrl.set(url);
    this.write(SIGN_IN_RETURN_URL_KEY, url);
  }

  private readReaderUrl(key: string): string | null {
    try {
      const url = sessionStorage.getItem(key);
      return url !== null && isReaderUrl(url) ? url : null;
    } catch {
      return null;
    }
  }

  private write(key: string, value: string): void {
    try {
      sessionStorage.setItem(key, value);
    } catch {
      // Reader restoration remains usable in memory when browser storage is blocked.
    }
  }

  private remove(key: string): void {
    try {
      sessionStorage.removeItem(key);
    } catch {
      // Same in-memory fallback as write() above.
    }
  }
}

function isReaderUrl(url: string): boolean {
  return url === '/' || url.startsWith('/?') || url.startsWith('/#');
}
