import { NgZone } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { PageScrollHost } from './page-scroll-host';
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
    root.style.scrollPaddingTop = '';
    restorePageScroll();
    jest.restoreAllMocks();
  });

  const pageHost = (): PageScrollHost =>
    new PageScrollHost(content, {
      view: window,
      outsideZone: inZone,
      announceWrite: () => undefined,
    });
  const scrollEvent = (): boolean => window.dispatchEvent(new Event('scroll'));

  describe('for a layer that leads by 96 (#2088)', () => {
    const announced: number[] = [];
    const leadingHost = (): PageScrollHost => {
      const layer = new PageLayer(document.createElement('div'));
      layer.leadBy(96);
      return new PageScrollHost(
        content,
        { view: window, outsideZone: inZone, announceWrite: (top) => announced.push(top) },
        layer,
      );
    };

    beforeEach(() => (announced.length = 0));

    it('counts from where its content starts', () => {
      const host = leadingHost();
      page.top = 400;
      expect(host.scrollTop).toBe(304);

      host.scrollTop = 0;
      expect(page.top).toBe(96);
      expect(announced).toEqual([96]);
    });

    it('reads 0 in the spacer above its content', () => {
      page.top = 40;
      expect(leadingHost().scrollTop).toBe(0);
    });

    it('scrolls to and measures past its lead', () => {
      Object.defineProperty(root, 'scrollHeight', { value: 5000, configurable: true });
      const scrollTo = jest.spyOn(window, 'scrollTo').mockImplementation(() => undefined);
      const host = leadingHost();

      host.scrollTo({ top: 0, behavior: 'smooth' });

      expect(scrollTo).toHaveBeenCalledWith({ top: 96, behavior: 'smooth' });
      expect(announced).toEqual([96]);
      expect(host.scrollHeight).toBe(4904);
      delete (root as unknown as { scrollHeight?: number }).scrollHeight;
    });
  });

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

  describe('for a layer pinned beneath another (#2038)', () => {
    const element = document.createElement('section');
    const layerHost = (layer: PageLayer): PageScrollHost =>
      new PageScrollHost(
        content,
        { view: window, outsideZone: inZone, announceWrite: () => undefined },
        layer,
      );

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
