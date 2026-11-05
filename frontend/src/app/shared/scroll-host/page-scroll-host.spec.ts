import { NgZone } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { PAGE_SCROLL_BLOCK_CLASS, PageScrollHost } from './page-scroll-host';
import { ScrollHosts } from './scroll-hosts.service';
import { PageLayer } from './page-layer';
import { restorePageScroll, trackPageScrollTop } from '../../../testing/scroll-testing';

const inZone = <T>(run: () => T): T => run();

describe('PageScrollHost', () => {
  const root = document.documentElement;
  const content = document.createElement('div');
  let page: { top: number };

  beforeEach(() => {
    page = trackPageScrollTop();
  });

  afterEach(() => {
    root.classList.remove(PAGE_SCROLL_BLOCK_CLASS);
    root.style.top = '';
    root.style.scrollPaddingTop = '';
    restorePageScroll();
    jest.restoreAllMocks();
  });

  const pageHost = (): PageScrollHost =>
    new PageScrollHost(content, { view: window, outsideZone: inZone });
  const scrollEvent = (): boolean => window.dispatchEvent(new Event('scroll'));
  const mutationsDelivered = (): Promise<void> => new Promise((resolve) => setTimeout(resolve));

  /** What a CDK BlockScrollStrategy does to `<html>` on enable, minus its viewport ruler. */
  function blockAt(top: number): void {
    root.style.top = `${-top}px`;
    root.classList.add(PAGE_SCROLL_BLOCK_CLASS);
    page.top = 0;
  }

  /** …and on disable: the class goes, then the window is scrolled back. */
  function releaseTo(top: number): void {
    root.style.top = '';
    root.classList.remove(PAGE_SCROLL_BLOCK_CLASS);
    page.top = top;
  }

  it('reads and writes the document offset', () => {
    const host = pageHost();
    host.scrollTop = 640;
    expect(page.top).toBe(640);
    expect(host.scrollTop).toBe(640);
  });

  it('measures from the viewport', () => {
    Object.defineProperty(window, 'innerHeight', { value: 812, configurable: true });
    Object.defineProperty(root, 'scrollHeight', { value: 5000, configurable: true });
    page.top = 300;
    const host = pageHost();
    expect(host.clientHeight).toBe(812);
    expect(host.scrollHeight).toBe(5000);
    expect(host.viewportTop()).toBe(0);
    expect(host.toContentY(100)).toBe(400);
    expect(host.content).toBe(content);
    expect(host.intersectionRoot).toBeNull();
  });

  it('reads the band chrome covers from its content, like an element host', () => {
    document.body.append(content);
    content.style.scrollPaddingTop = '96px';
    expect(pageHost().scrollPaddingTop()).toBe(96);
    content.style.scrollPaddingTop = '';
    content.remove();
  });

  it('scrolls the window', () => {
    const scrollTo = jest.spyOn(window, 'scrollTo').mockImplementation(() => undefined);
    pageHost().scrollTo({ top: 0, behavior: 'smooth' });
    expect(scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'smooth' });
  });

  it('listens to window scrolls until removed', () => {
    const listener = jest.fn();
    const stop = pageHost().listen(listener);
    scrollEvent();
    stop();
    scrollEvent();
    expect(listener).toHaveBeenCalledTimes(1);
  });

  it('watches the window and the visual viewport until removed', () => {
    const visualViewport = new EventTarget();
    Object.defineProperty(window, 'visualViewport', { value: visualViewport, configurable: true });
    const listener = jest.fn();
    const stop = pageHost().watchResize(listener);
    window.dispatchEvent(new Event('resize'));
    visualViewport.dispatchEvent(new Event('resize'));
    stop();
    window.dispatchEvent(new Event('resize'));
    visualViewport.dispatchEvent(new Event('resize'));
    expect(listener).toHaveBeenCalledTimes(2);
  });

  describe('under a CDK block', () => {
    it('reports the pinned offset, not the 0 the pinned page scrolls to', () => {
      page.top = 2538;
      const host = pageHost();
      blockAt(2538);
      expect(host.scrollTop).toBe(2538);
      expect(host.toContentY(10)).toBe(2548);
    });

    it('hears no scroll while blocked; the restore lands where it left', () => {
      page.top = 2538;
      const host = pageHost();
      const heard: number[] = [];
      host.listen(() => heard.push(host.scrollTop));
      blockAt(2538);
      scrollEvent();
      releaseTo(2538);
      scrollEvent();
      expect(heard).toEqual([2538]);
    });

    it('holds a write made while blocked and lands it after the release', async () => {
      page.top = 2538;
      const host = pageHost();
      const stop = host.listen(() => undefined);
      blockAt(2538);
      host.scrollTop = 900;
      expect(host.scrollTop).toBe(900);
      expect(root.style.top).toBe('-900px');

      releaseTo(2538);
      await mutationsDelivered();

      expect(page.top).toBe(900);
      stop();
    });

    it('holds a scrollTo the same way instead of scrolling the pinned window', () => {
      const scrollTo = jest.spyOn(window, 'scrollTo').mockImplementation(() => undefined);
      page.top = 2538;
      const host = pageHost();
      blockAt(2538);
      host.scrollTo({ top: 0, behavior: 'smooth' });
      expect(scrollTo).not.toHaveBeenCalled();
      expect(host.scrollTop).toBe(0);
    });
  });

  describe('for a layer pinned beneath another (#2038)', () => {
    const element = document.createElement('section');
    const layerHost = (layer: PageLayer): PageScrollHost =>
      new PageScrollHost(content, { view: window, outsideZone: inZone }, layer);

    afterEach(() => {
      element.className = '';
      element.removeAttribute('style');
    });

    it('reports its pin and hears none of the page the layer above scrolls', () => {
      const layer = new PageLayer(element);
      const host = layerHost(layer);
      const heard: number[] = [];
      const stop = host.listen(() => heard.push(host.scrollTop));

      layer.pinAt(1200);
      page.top = 300;
      scrollEvent();

      expect(host.scrollTop).toBe(1200);
      expect(host.toContentY(10)).toBe(1210);
      expect(heard).toEqual([]);
      stop();
    });

    it('moves its pin on a write and leaves the page to the layer above', () => {
      const scrollTo = jest.spyOn(window, 'scrollTo').mockImplementation(() => undefined);
      const layer = new PageLayer(element);
      const host = layerHost(layer);
      layer.pinAt(1200);
      page.top = 300;

      host.scrollTop = 900;
      host.scrollTo({ top: 800, behavior: 'smooth' });

      expect(layer.pinnedTop).toBe(800);
      expect(page.top).toBe(300);
      expect(scrollTo).not.toHaveBeenCalled();
    });

    it('reads its pin, not the block, while a CDK dialog holds the page', () => {
      const layer = new PageLayer(element);
      const host = layerHost(layer);
      layer.pinAt(1200);
      page.top = 300;

      blockAt(300);

      expect(host.scrollTop).toBe(1200);
    });

    it('lands a write held under a CDK block on its pin once pinned beneath another', async () => {
      const layer = new PageLayer(element);
      const host = layerHost(layer);
      const stop = host.listen(() => undefined);
      page.top = 1200;
      blockAt(1200);
      host.scrollTop = 900;

      layer.pinAt(1200);
      releaseTo(300);
      await mutationsDelivered();

      expect(layer.pinnedTop).toBe(900);
      expect(page.top).toBe(300);
      stop();
    });

    it('is the page again once unpinned', () => {
      const layer = new PageLayer(element);
      const host = layerHost(layer);
      layer.pinAt(1200);

      page.top = layer.unpin();

      expect(host.scrollTop).toBe(1200);
    });
  });
});

describe('ScrollHosts.forPage', () => {
  it('reads the page with the given content, listening outside the Angular zone (#501)', () => {
    const zone = TestBed.inject(NgZone);
    const content = document.createElement('div');
    const zones: boolean[] = [];
    const host = TestBed.inject(ScrollHosts).forPage(content);
    zone.run(() => host.listen(() => zones.push(NgZone.isInAngularZone())));
    window.dispatchEvent(new Event('scroll'));
    expect(host).toBeInstanceOf(PageScrollHost);
    expect(host.content).toBe(content);
    expect(zones).toEqual([false]);
  });

  it('hands the layer it is given to the host', () => {
    const element = document.createElement('section');
    const layer = new PageLayer(element);
    layer.pinAt(700);
    const host = TestBed.inject(ScrollHosts).forPage(document.createElement('div'), layer);
    expect(host.scrollTop).toBe(700);
  });
});
