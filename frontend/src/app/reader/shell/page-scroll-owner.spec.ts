import { TestBed } from '@angular/core/testing';
import { PAGE_OVERLAID_CLASS, PageScrollOwner } from './page-scroll-owner';
import { PageLayer } from '../../shared/scroll-host/page-layer';
import { PAGE_SCROLL_BLOCK_CLASS } from '../../shared/scroll-host/page-scroll-host';
import { restorePageScroll, trackPageScrollTop } from '../../../testing/scroll-testing';

describe('PageScrollOwner', () => {
  const root = document.documentElement;
  let page: { top: number };
  let owner: PageScrollOwner;

  beforeEach(() => {
    page = trackPageScrollTop();
    TestBed.configureTestingModule({ providers: [PageScrollOwner] });
    owner = TestBed.inject(PageScrollOwner);
  });

  afterEach(() => {
    TestBed.resetTestingModule();
    root.classList.remove(PAGE_SCROLL_BLOCK_CLASS);
    root.style.top = '';
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

  it('pins the layer beneath where it stands and starts the entering one at the top', () => {
    const { list, article } = listUnderArticle();

    expect(list.pinned).toBe(true);
    expect(list.pinnedTop).toBe(1200);
    expect(article.pinned).toBe(false);
    expect(page.top).toBe(0);
    expect(root.classList).toContain(PAGE_OVERLAID_CLASS);
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

  it('lands the hand-back after a CDK dialog that holds the page lets go', async () => {
    const { list, article } = listUnderArticle();
    root.style.top = '-640px';
    root.classList.add(PAGE_SCROLL_BLOCK_CLASS);
    page.top = 0;

    owner.leave(article);
    expect(article.pinnedTop).toBe(640);
    expect(list.pinned).toBe(false);
    expect(root.style.top).toBe('-1200px');

    root.style.top = '';
    root.classList.remove(PAGE_SCROLL_BLOCK_CLASS);
    page.top = 640;
    await new Promise((resolve) => setTimeout(resolve));

    expect(page.top).toBe(1200);
  });

  it('drops its class from the page with the reader', () => {
    listUnderArticle();
    TestBed.resetTestingModule();
    expect(root.classList).not.toContain(PAGE_OVERLAID_CLASS);
  });
});
