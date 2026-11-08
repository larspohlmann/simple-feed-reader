import { TestBed } from '@angular/core/testing';
import { PAGE_LEAD_REM, PAGE_OVERLAID_CLASS, PageScrollOwner } from './page-scroll-owner';
import { PAGE_LEAD, PageLayer } from '../../shared/scroll-host/page-layer';
import { LayoutService } from '../layout.service';
import { restorePageScroll, trackPageScrollTop } from '../../../testing/scroll-testing';

describe('PageScrollOwner', () => {
  const root = document.documentElement;
  const lead = PAGE_LEAD_REM * 16;
  let page: { top: number };
  let owner: PageScrollOwner;
  let coarse: boolean;

  beforeEach(() => {
    page = trackPageScrollTop();
    coarse = true;
    TestBed.configureTestingModule({
      providers: [
        PageScrollOwner,
        { provide: LayoutService, useValue: { isCoarse: () => coarse } },
      ],
    });
    owner = TestBed.inject(PageScrollOwner);
  });

  afterEach(() => {
    TestBed.resetTestingModule();
    restorePageScroll();
  });

  /** A layer whose box starts `documentTop` down the document, measured against the live page. */
  function layerAt(documentTop: number): PageLayer {
    const element = document.createElement('div');
    jest
      .spyOn(element, 'getBoundingClientRect')
      .mockImplementation(() => ({ top: documentTop - page.top }) as DOMRect);
    return new PageLayer(element);
  }

  /** The list scrolled to 1200, with an article entered over it. */
  function listUnderArticle(): { list: PageLayer; article: PageLayer } {
    const list = layerAt(0);
    owner.enter(list);
    page.top = 1200;
    const article = layerAt(0);
    owner.enter(article);
    return { list, article };
  }

  const state = (layer: PageLayer): string => (layer.pinned ? 'pinned' : 'in flow');

  it('leaves the first layer in the flow and the page where it is', () => {
    page.top = 1200;
    const list = layerAt(0);

    owner.enter(list);

    expect(list.pinned).toBe(false);
    expect(page.top).toBe(1200);
    expect(root.classList).not.toContain(PAGE_OVERLAID_CLASS);
  });

  it('pins the layer beneath where it stands and opens the entering one at its lead', () => {
    const { list, article } = listUnderArticle();

    expect(list.pinned).toBe(true);
    expect(list.pinnedTop).toBe(1200);
    expect(article.pinned).toBe(false);
    expect(page.top).toBe(lead);
    expect(root.classList).toContain(PAGE_OVERLAID_CLASS);
  });

  it('leads every layer over another, never the first (#2088)', () => {
    const { list, article } = listUnderArticle();

    expect(list.lead).toBe(0);
    expect(article.lead).toBe(lead);
    expect(article.element.style.getPropertyValue(PAGE_LEAD)).toBe(`${lead}px`);
  });

  it('opens a layer at the very top on a desktop, which has no bar to keep collapsed', () => {
    coarse = false;
    const { article } = listUnderArticle();

    expect(article.lead).toBe(0);
    expect(page.top).toBe(0);
  });

  it('hands the page back in one call: the leaver pinned and the list in flow before it moves', () => {
    const { list, article } = listUnderArticle();
    page.top = 640;
    const writes: string[] = [];
    Object.defineProperty(root, 'scrollTop', {
      configurable: true,
      get: () => page.top,
      set: (top: number) => {
        writes.push(`article ${state(article)}, list ${state(list)}, page ${top}`);
        page.top = top;
      },
    });

    owner.leave(article);

    expect(writes).toEqual(['article pinned, list in flow, page 1200']);
    expect(article.pinnedTop).toBe(640);
    expect(root.classList).not.toContain(PAGE_OVERLAID_CLASS);
  });

  it('hands a link reader’s page back to the article, then the article’s to the list', () => {
    const { list, article } = listUnderArticle();
    page.top = 640;
    const link = layerAt(0);
    owner.enter(link);
    expect(article.pinnedTop).toBe(640);
    page.top = 300;

    owner.leave(link);
    expect(page.top).toBe(640);
    expect([state(article), state(list)]).toEqual(['in flow', 'pinned']);

    owner.leave(article);
    expect(page.top).toBe(1200);
    expect(state(list)).toBe('in flow');
  });

  it('pins only the layer that was in the flow', () => {
    const pinnedTwice: string[] = [];
    const watch = (name: string, layer: PageLayer): PageLayer => {
      const pinAt = layer.pinAt.bind(layer);
      jest.spyOn(layer, 'pinAt').mockImplementation((top) => {
        if (layer.pinned) pinnedTwice.push(name);
        pinAt(top);
      });
      return layer;
    };
    const list = watch('list', layerAt(0));
    const article = watch('article', layerAt(0));
    const link = watch('link', layerAt(0));
    const next = watch('next', layerAt(0));

    owner.enter(list);
    owner.enter(article);
    owner.enter(link);
    owner.leave(article);
    owner.leave(link);
    owner.enter(next);
    owner.leave(next);

    expect(pinnedTwice).toEqual([]);
  });

  it('changes nothing on the page when a layer leaves from beneath the top', () => {
    const { list, article } = listUnderArticle();
    page.top = 640;

    owner.leave(list);
    expect(page.top).toBe(640);
    expect(article.pinned).toBe(false);

    owner.leave(article);
    expect(page.top).toBe(640);
    expect(article.pinned).toBe(true);
  });

  it('drops its class from the page with the reader', () => {
    listUnderArticle();
    TestBed.resetTestingModule();
    expect(root.classList).not.toContain(PAGE_OVERLAID_CLASS);
  });

  describe('when iOS puts back the offset a switch replaced (#2074)', () => {
    beforeEach(() => {
      jest.useFakeTimers();
      Object.defineProperty(root, 'scrollHeight', { value: 30000, configurable: true });
    });

    afterEach(() => {
      jest.useRealTimers();
      delete (root as unknown as { scrollHeight?: number }).scrollHeight;
    });

    it('holds the entering layer at its lead, though pinning cut the offset short first', () => {
      const list = layerAt(0);
      owner.enter(list);
      page.top = 4970;
      const pinAt = list.pinAt.bind(list);
      jest.spyOn(list, 'pinAt').mockImplementation((top) => {
        pinAt(top);
        page.top = 480;
      });

      owner.enter(layerAt(0));
      jest.advanceTimersByTime(110);
      page.top = 4970;
      jest.advanceTimersByTime(32);

      expect(page.top).toBe(lead);
    });
  });
});
