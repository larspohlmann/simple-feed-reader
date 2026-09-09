import { TestBed } from '@angular/core/testing';
import { NavigationEnd, Router } from '@angular/router';
import { Subject } from 'rxjs';
import { ReaderLocationService } from './reader-location.service';

describe('ReaderLocationService', () => {
  let events: Subject<unknown>;

  const build = (): ReaderLocationService => TestBed.inject(ReaderLocationService);

  beforeEach(() => {
    sessionStorage.clear();
    events = new Subject<unknown>();
    TestBed.configureTestingModule({
      providers: [{ provide: Router, useValue: { events } }],
    });
  });

  it('restores a reader and pending sign-in URL after a reload', () => {
    const initial = build();
    initial.rememberAttemptedReaderUrl('/?tag=17&entry=42-example#comments');

    TestBed.resetTestingModule();
    events = new Subject<unknown>();
    TestBed.configureTestingModule({
      providers: [{ provide: Router, useValue: { events } }],
    });

    const restored = build();
    expect(restored.savedReaderUrl()).toBe('/?tag=17&entry=42-example#comments');
    expect(restored.consumeSignInReturnUrl()).toBe('/?tag=17&entry=42-example#comments');
  });

  it('discards invalid stored URLs after a reload', () => {
    sessionStorage.setItem('sfr.reader-location.current', 'https://example.test');
    sessionStorage.setItem('sfr.reader-location.sign-in-return', '/settings/organise');

    const service = build();

    expect(service.savedReaderUrl()).toBe('/');
    expect(service.consumeSignInReturnUrl()).toBe('/');
  });

  it('remembers the final URL of a successful reader navigation', () => {
    const service = build();

    events.next(new NavigationEnd(1, '/temporary', '/?tag=17&entry=42-example#comments'));

    expect(service.savedReaderUrl()).toBe('/?tag=17&entry=42-example#comments');
  });

  it('keeps the last reader URL when a navigation ends outside the reader', () => {
    const service = build();
    events.next(new NavigationEnd(1, '/?tag=17', '/?tag=17'));

    events.next(new NavigationEnd(2, '/settings', '/settings'));
    events.next(new NavigationEnd(3, '/discover', '/discover'));

    expect(service.savedReaderUrl()).toBe('/?tag=17');
  });

  it('rejects external and non-reader URLs without replacing remembered destinations', () => {
    const service = build();
    service.rememberAttemptedReaderUrl('/?tag=17&entry=42-example#comments');

    for (const url of [
      'https://example.test',
      '//example.test',
      '/settings',
      '/discover',
      '/login',
    ]) {
      service.rememberAttemptedReaderUrl(url);
    }

    expect(service.savedReaderUrl()).toBe('/?tag=17&entry=42-example#comments');
    expect(service.consumeSignInReturnUrl()).toBe('/?tag=17&entry=42-example#comments');
  });

  it('uses the saved reader URL as the pending destination after a session expires', () => {
    const service = build();
    events.next(new NavigationEnd(1, '/?tag=17', '/?tag=17&entry=42-example#comments'));

    service.rememberSavedReaderUrlForSignIn();

    expect(service.consumeSignInReturnUrl()).toBe('/?tag=17&entry=42-example#comments');
    expect(service.consumeSignInReturnUrl()).toBe('/');
  });

  it('clears only the pending sign-in destination', () => {
    const service = build();
    service.rememberAttemptedReaderUrl('/?tag=17');

    service.clearSignInReturnUrl();

    expect(service.savedReaderUrl()).toBe('/?tag=17');
    expect(service.consumeSignInReturnUrl()).toBe('/');
  });

  it('falls back to the reader root when no stored destination is available', () => {
    const service = build();

    expect(service.savedReaderUrl()).toBe('/');
    expect(service.consumeSignInReturnUrl()).toBe('/');
  });

  it('survives unavailable session storage', () => {
    const getItem = jest.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('storage blocked');
    });

    expect(() => build()).not.toThrow();
    getItem.mockRestore();

    const setItem = jest.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('storage blocked');
    });
    const removeItem = jest.spyOn(Storage.prototype, 'removeItem').mockImplementation(() => {
      throw new Error('storage blocked');
    });
    const service = build();

    expect(() => service.rememberAttemptedReaderUrl('/?tag=17')).not.toThrow();
    expect(() => service.clearSignInReturnUrl()).not.toThrow();
    expect(() => service.consumeSignInReturnUrl()).not.toThrow();

    setItem.mockRestore();
    removeItem.mockRestore();
  });
});
