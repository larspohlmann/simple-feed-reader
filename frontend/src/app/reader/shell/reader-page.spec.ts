import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { READER_PAGE_CLASS, holdReaderPage } from './reader-page';
import { PAGE_HELD_CLASS } from '../../shared/page-hold/page-hold';
import { restorePageScroll, trackPageScrollTop } from '../../../testing/scroll-testing';

describe('holdReaderPage', () => {
  const root = document.documentElement;
  let page: { top: number };

  beforeEach(() => {
    Object.defineProperty(window.history, 'scrollRestoration', {
      value: 'auto',
      writable: true,
      configurable: true,
    });
    page = trackPageScrollTop();
  });

  afterEach(() => {
    TestBed.resetTestingModule();
    restorePageScroll();
  });

  function hold(locked = signal(false)): void {
    TestBed.runInInjectionContext(() => holdReaderPage(locked));
    TestBed.tick();
  }

  function lock(locked: ReturnType<typeof signal<boolean>>, value: boolean): void {
    locked.set(value);
    TestBed.tick();
  }

  it('claims the page while the reader is mounted', () => {
    hold();
    expect(root.classList).toContain(READER_PAGE_CLASS);
    expect(history.scrollRestoration).toBe('manual');

    TestBed.resetTestingModule();

    expect(root.classList).not.toContain(READER_PAGE_CLASS);
    expect(history.scrollRestoration).toBe('auto');
  });

  it('leaves the page at the top for the next route', () => {
    hold();
    page.top = 3000;
    TestBed.resetTestingModule();
    expect(page.top).toBe(0);
  });

  it('holds the page where it is while locked, without pinning <html> (#2088)', () => {
    page.top = 1200;
    const locked = signal(false);
    hold(locked);

    lock(locked, true);
    expect(root.classList).toContain(PAGE_HELD_CLASS);
    expect(root.classList).not.toContain('cdk-global-scrollblock');
    expect(page.top).toBe(1200);

    lock(locked, false);
    expect(root.classList).not.toContain(PAGE_HELD_CLASS);
    expect(page.top).toBe(1200);
  });

  it('lets go of a held page with the reader', () => {
    const locked = signal(true);
    hold(locked);

    TestBed.resetTestingModule();

    expect(root.classList).not.toContain(PAGE_HELD_CLASS);
  });
});
