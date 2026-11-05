import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { READER_PAGE_CLASS, holdReaderPage } from './reader-page';
import { PAGE_SCROLL_BLOCK_CLASS } from '../../shared/scroll-host/page-scroll-host';
import { restorePageScroll, trackPageScrollTop } from '../../../testing/scroll-testing';
import {
  MockResizeObserver,
  installMockResizeObserver,
} from '../../../testing/resize-observer-testing';

describe('holdReaderPage', () => {
  const root = document.documentElement;
  const content = document.createElement('div');
  const originalResizeObserver = globalThis.ResizeObserver;
  let page: { top: number };

  beforeEach(() => {
    Object.defineProperty(window.history, 'scrollRestoration', {
      value: 'auto',
      writable: true,
      configurable: true,
    });
    page = trackPageScrollTop();
    installMockResizeObserver();
    jest.spyOn(window, 'scroll').mockImplementation(() => undefined);
  });

  afterEach(() => {
    TestBed.resetTestingModule();
    root.classList.remove(PAGE_SCROLL_BLOCK_CLASS);
    root.style.top = '';
    delete (root as unknown as { scrollHeight?: number }).scrollHeight;
    restorePageScroll();
    globalThis.ResizeObserver = originalResizeObserver;
    jest.restoreAllMocks();
  });

  function hold(locked = signal(false)): void {
    TestBed.runInInjectionContext(() => holdReaderPage(content, locked));
    TestBed.tick();
  }

  function lock(locked: ReturnType<typeof signal<boolean>>, value: boolean): void {
    locked.set(value);
    TestBed.tick();
  }

  const pageHeight = (height: number): void => {
    Object.defineProperty(root, 'scrollHeight', { value: height, configurable: true });
  };
  const contentGrew = (): void => MockResizeObserver.instances.forEach((each) => each.fire());
  const mutationsDelivered = (): Promise<void> => new Promise((resolve) => setTimeout(resolve));

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
    pageHeight(5000);
    page.top = 1200;
    const locked = signal(false);
    hold(locked);

    lock(locked, true);
    expect(root.classList).toContain(PAGE_SCROLL_BLOCK_CLASS);
    expect(root.style.top).toBe('-1200px');

    lock(locked, false);
    expect(root.classList).not.toContain(PAGE_SCROLL_BLOCK_CLASS);
    expect(window.scroll).toHaveBeenCalledWith(0, 1200);
  });

  it('pins a page that only outgrows the viewport once locked, as on a deep link', () => {
    pageHeight(window.innerHeight);
    const locked = signal(false);
    hold(locked);

    lock(locked, true);
    expect(root.classList).not.toContain(PAGE_SCROLL_BLOCK_CLASS);

    pageHeight(5000);
    contentGrew();
    expect(root.classList).toContain(PAGE_SCROLL_BLOCK_CLASS);
  });

  it('watches the reader content, since <html> and <body> hold at the viewport height', () => {
    const locked = signal(false);
    hold(locked);

    lock(locked, true);

    expect(MockResizeObserver.instances.some((each) => each.targets.has(content))).toBe(true);
  });

  it('pins the page itself once a dialog that held it lets go', async () => {
    pageHeight(5000);
    page.top = 1200;
    root.classList.add(PAGE_SCROLL_BLOCK_CLASS);
    const locked = signal(false);
    hold(locked);

    lock(locked, true);
    expect(root.style.top).toBe('');

    root.classList.remove(PAGE_SCROLL_BLOCK_CLASS);
    await mutationsDelivered();
    expect(root.classList).toContain(PAGE_SCROLL_BLOCK_CLASS);
    expect(root.style.top).toBe('-1200px');
  });

  it('stops retrying once unlocked', async () => {
    pageHeight(window.innerHeight);
    const locked = signal(false);
    hold(locked);
    lock(locked, true);

    lock(locked, false);
    pageHeight(5000);
    root.classList.add(PAGE_SCROLL_BLOCK_CLASS);
    root.classList.remove(PAGE_SCROLL_BLOCK_CLASS);
    await mutationsDelivered();

    expect(root.classList).not.toContain(PAGE_SCROLL_BLOCK_CLASS);
    expect(MockResizeObserver.instances.every((each) => each.targets.size === 0)).toBe(true);
  });
});
