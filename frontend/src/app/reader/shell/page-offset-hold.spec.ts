import { TestBed } from '@angular/core/testing';
import { ScrollHosts } from '../../shared/scroll-host/scroll-hosts.service';
import type { ScrollHost } from '../../shared/scroll-host/scroll-host';
import { restorePageScroll, trackPageScrollTop } from '../../../testing/scroll-testing';
import { HOLD_MS, PageOffsetHold } from './page-offset-hold';

const FRAME_MS = 16;
const PAGE_HEIGHT = 30000;

describe('PageOffsetHold', () => {
  const root = document.documentElement;
  let page: { top: number };
  let offsetHold: PageOffsetHold;
  let host: ScrollHost;

  beforeEach(() => {
    jest.useFakeTimers();
    page = trackPageScrollTop();
    Object.defineProperty(root, 'scrollHeight', { value: PAGE_HEIGHT, configurable: true });
    host = TestBed.inject(ScrollHosts).forPage(document.body);
    offsetHold = TestBed.runInInjectionContext(() => new PageOffsetHold(host));
  });

  afterEach(() => {
    TestBed.resetTestingModule();
    delete (root as unknown as { scrollHeight?: number }).scrollHeight;
    restorePageScroll();
    jest.useRealTimers();
  });

  /** iOS WebKit's revert seen in #2074: the old offset back, 112 ms after the write. */
  function webKitRevertsTo(oldTop: number): void {
    jest.advanceTimersByTime(112);
    page.top = oldTop;
    jest.advanceTimersByTime(FRAME_MS * 2);
  }

  it('writes the offset it hands off', () => {
    page.top = 1084;

    offsetHold.handOff({ from: 1084, to: 0 });

    expect(page.top).toBe(0);
  });

  it('puts the offset back when the page returns to the one it left', () => {
    offsetHold.handOff({ from: 1084, to: 0 });

    webKitRevertsTo(1084);

    expect(page.top).toBe(0);
  });

  it('lets the page go once the hold has run its time', () => {
    offsetHold.handOff({ from: 1084, to: 0 });
    jest.advanceTimersByTime(HOLD_MS + FRAME_MS * 2);

    page.top = 1084;
    jest.advanceTimersByTime(FRAME_MS * 2);

    expect(page.top).toBe(1084);
  });

  it('gives the page to a user who touches it', () => {
    offsetHold.handOff({ from: 500, to: 0 });
    window.dispatchEvent(new Event('touchstart'));

    webKitRevertsTo(500);

    expect(page.top).toBe(500);
  });

  it('yields to the app writing another offset, such as a restored reading position', () => {
    offsetHold.handOff({ from: 500, to: 0 });
    host.scrollTop = 2400;

    jest.advanceTimersByTime(FRAME_MS * 4);

    expect(page.top).toBe(2400);
  });

  it('keeps holding through another write of the same offset', () => {
    offsetHold.handOff({ from: 1084, to: 0 });
    host.scrollTop = 0;

    webKitRevertsTo(1084);

    expect(page.top).toBe(0);
  });

  it('leaves the page alone when it moves anywhere but back', () => {
    offsetHold.handOff({ from: 1084, to: 0 });
    jest.advanceTimersByTime(100);

    page.top = 40;
    jest.advanceTimersByTime(FRAME_MS * 4);

    expect(page.top).toBe(40);
  });

  it('undoes a return to the old offset cut short by a shorter page', () => {
    offsetHold.handOff({ from: 40000, to: 0 });

    webKitRevertsTo(PAGE_HEIGHT - window.innerHeight);

    expect(page.top).toBe(0);
  });

  it('lands an offset the page was too short for once it grows (#2088)', () => {
    let height = window.innerHeight;
    Object.defineProperty(root, 'scrollHeight', { get: () => height, configurable: true });
    Object.defineProperty(root, 'scrollTop', {
      configurable: true,
      get: () => page.top,
      set: (top: number) => (page.top = Math.min(top, height - window.innerHeight)),
    });
    offsetHold.handOff({ from: 1084, to: 96 });
    jest.advanceTimersByTime(FRAME_MS * 2);
    expect(page.top).toBe(0);

    height = PAGE_HEIGHT;
    jest.advanceTimersByTime(FRAME_MS * 2);

    expect(page.top).toBe(96);
  });

  it('takes the end of the page as holding a target past it', () => {
    const end = PAGE_HEIGHT - window.innerHeight;
    offsetHold.handOff({ from: 0, to: 40000 });
    page.top = end;

    jest.advanceTimersByTime(FRAME_MS * 4);

    expect(page.top).toBe(end);
  });
});
