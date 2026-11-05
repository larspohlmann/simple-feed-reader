import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { READER_PAGE_CLASS, holdReaderPage } from './reader-page';
import { trackScrollTop } from '../../../testing/scroll-testing';

describe('holdReaderPage', () => {
  const root = document.documentElement;
  let page: { top: number };

  beforeEach(() => {
    Object.defineProperty(document, 'scrollingElement', { value: root, configurable: true });
    Object.defineProperty(window.history, 'scrollRestoration', {
      value: 'auto',
      writable: true,
      configurable: true,
    });
    page = trackScrollTop(root);
  });

  afterEach(() => jest.restoreAllMocks());

  function hold(locked = signal(false)): void {
    TestBed.runInInjectionContext(() => holdReaderPage(locked));
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

  it('pins the page while locked, the way a CDK dialog does', () => {
    Object.defineProperty(root, 'scrollHeight', { value: 5000, configurable: true });
    const restore = jest.spyOn(window, 'scroll').mockImplementation(() => undefined);
    page.top = 1200;
    const locked = signal(false);
    hold(locked);

    locked.set(true);
    TestBed.tick();
    expect(root.classList).toContain('cdk-global-scrollblock');
    expect(root.style.top).toBe('-1200px');

    locked.set(false);
    TestBed.tick();
    expect(root.classList).not.toContain('cdk-global-scrollblock');
    expect(restore).toHaveBeenCalledWith(0, 1200);
  });
});
