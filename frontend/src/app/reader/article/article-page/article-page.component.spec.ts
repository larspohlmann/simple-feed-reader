import { Component, Injectable } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { installMockResizeObserver } from '../../../../testing/resize-observer-testing';
import { NO_EMBED_DETAILS } from '../../../../testing/embed-details-testing';
import { provideArticlePageStubs } from '../../../../testing/article-page-testing';
import { StubArticlePageSource } from '../../../../testing/article-page-source-testing';
import { provideAccountIdentity } from '../../../../testing/account-identity-testing';
import { ArticlePageSlotDirective } from './article-page-slot.directive';
import { ArticlePageComponent } from './article-page.component';
import { scrollerOf, stubCssEscape } from '../../../../testing/scroll-testing';
import { noAutoplayRegion, provideArticlePage } from './provide-article-page';

@Injectable()
class StubPageSource extends StubArticlePageSource {
  constructor() {
    super({
      url: 'https://news.test/a',
      title: 'The page title',
      shareTitle: 'The share title',
      nameplate: 'news.test',
      body: {
        html: '<h2 id="one">One</h2><p>Body words</p>',
        pageUrl: 'https://news.test/a',
      },
      canRefresh: true,
    });
  }
}

@Component({
  imports: [ArticlePageComponent, ArticlePageSlotDirective],
  providers: [
    StubPageSource,
    provideArticlePage({ source: StubPageSource, autoplayRegion: noAutoplayRegion }),
  ],
  template: `
    <app-article-page backLabel="Back" (openOriginal)="opened = opened + 1">
      <button pageBarLead class="lead-probe" type="button">Lead</button>
      <button pageBarFlags class="flags-probe" type="button">Flags</button>
      <span pageByline class="byline-probe">The byline</span>
      <span pageMetaTail class="tail-probe">The tail</span>
      <div pageHead class="head-probe">Head</div>
      <ng-template appArticlePageSlot="notices"><p class="notices-probe">Notices</p></ng-template>
      <ng-template appArticlePageSlot="afterBody"><p class="after-probe">After</p></ng-template>
      <ng-template appArticlePageSlot="footer"><p class="footer-probe">Footer</p></ng-template>
      <span pageCorner class="corner-probe">Corner</span>
    </app-article-page>
  `,
})
class HostComponent {
  opened = 0;
}

describe('ArticlePageComponent', () => {
  let fixture: ComponentFixture<HostComponent>;
  let source: StubPageSource;

  beforeEach(() => {
    installMockResizeObserver();
    TestBed.configureTestingModule({
      imports: [HostComponent, provideTranslocoTesting()],
      providers: [provideAccountIdentity(), NO_EMBED_DETAILS, ...provideArticlePageStubs()],
    });
  });

  function mount(configure: (stub: StubPageSource) => void = () => undefined): HTMLElement {
    fixture = TestBed.createComponent(HostComponent);
    source = fixture.debugElement.injector.get(StubPageSource);
    configure(source);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  const rerender = (): void => fixture.detectChanges();

  it('heads the page with the source’s title, nameplate and meta line', () => {
    const element = mount();

    expect(element.querySelector('h1.title')!.textContent!.trim()).toBe('The page title');
    expect(element.querySelector('.bar-title')!.textContent!.trim()).toBe('news.test');
    expect(element.querySelector('.meta .byline-probe')).not.toBeNull();
    expect(element.querySelector('.meta .tail-probe')).not.toBeNull();
    const original = element.querySelector<HTMLAnchorElement>(
      '.meta a[href="https://news.test/a"]',
    )!;
    expect(original.textContent).toContain('Open original');
  });

  it('keeps a hidden title for screen readers only', () => {
    const element = mount((stub) => stub.titleHidden.set(true));

    expect(element.querySelector('h1.title')!.classList).toContain('sr-only');
  });

  it('reports the open-original click', () => {
    const element = mount();

    element.querySelector<HTMLAnchorElement>('.meta a[href="https://news.test/a"]')!.click();

    expect(fixture.componentInstance.opened).toBe(1);
  });

  it('puts the lead, refresh, share and flags in the bar in that order', () => {
    const element = mount();

    const nav = element.querySelector('.nav')!;
    const order = [...nav.children].map((child) => {
      if (child.matches('[aria-label="Reload article"]')) return 'refresh';
      if (child.matches('app-share-button')) return 'share';
      return child.className;
    });
    expect(order).toEqual(['lead-probe', 'refresh', 'share', 'flags-probe']);
  });

  it('refreshes through the source, and hides refresh when the source cannot', () => {
    const element = mount();
    element.querySelector<HTMLButtonElement>('.nav [aria-label="Reload article"]')!.click();
    expect(source.reload).toHaveBeenCalledTimes(1);

    source.canRefresh.set(false);
    rerender();
    expect(element.querySelector('.nav [aria-label="Reload article"]')).toBeNull();
  });

  it('renders the body, then the after-body slot, and the footer below', () => {
    const element = mount();

    const article = element.querySelector('article')!;
    const content = article.querySelector('.content')!;
    expect(content.textContent).toContain('Body words');
    const after = article.querySelector('.after-probe')!;
    const footer = article.querySelector('.footer-probe')!;
    expect(content.compareDocumentPosition(after) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(after.compareDocumentPosition(footer) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it('holds the gated parts back while loading, but not the footer', () => {
    const element = mount((stub) => stub.loading.set(true));

    expect(element.querySelector('.content')).toBeNull();
    expect(element.querySelector('.notices-probe')).toBeNull();
    expect(element.querySelector('.after-probe')).toBeNull();
    expect(element.querySelector('.footer-probe')).not.toBeNull();
    expect(element.querySelector('article')!.getAttribute('aria-busy')).toBe('true');
  });

  it('renders no body element when the source has none', () => {
    const element = mount((stub) => stub.body.set(null));

    expect(element.querySelector('.content')).toBeNull();
  });

  it('shows a failure with its detail and a retry that reloads', () => {
    const element = mount((stub) =>
      stub.failure.set({
        messageKey: 'reader.linkFailed',
        quiet: false,
        detail: 'HTTP 503',
      }),
    );

    const box = element.querySelector('app-warning-box.reader-fallback')!;
    expect(box.querySelector('.reader-error pre')!.textContent).toBe('HTTP 503');
    box.querySelector<HTMLButtonElement>('button.note-link')!.click();
    expect(source.reload).toHaveBeenCalledTimes(1);
    expect(element.querySelector('a.open-original')).toBeNull();
  });

  it('shows a quiet failure as a plain note', () => {
    const element = mount((stub) =>
      stub.failure.set({
        messageKey: 'reader.fallback.empty',
        quiet: true,
        detail: 'x',
      }),
    );

    expect(element.querySelector('app-warning-box')).toBeNull();
    expect(element.querySelector('p.reader-fallback-quiet button.note-link')).not.toBeNull();
    expect(element.querySelector('.reader-error')).toBeNull();
  });

  it('offers the original after the lead image when the failure asks for it', () => {
    const element = mount((stub) => {
      stub.failure.set({
        messageKey: 'reader.linkFailed',
        quiet: false,
        detail: null,
      });
      stub.body.set(null);
      stub.lead.set({ url: 'https://news.test/lead.jpg', width: null, height: null });
    });

    const lead = element.querySelector('img.lead-image')!;
    const original = element.querySelector('a.open-original')!;
    expect(original.getAttribute('href')).toBe('https://news.test/a');
    expect(lead.compareDocumentPosition(original) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it('drops a lead image that fails to load', () => {
    const element = mount((stub) =>
      stub.lead.set({ url: 'https://news.test/lead.jpg', width: 4, height: 3 }),
    );

    const lead = element.querySelector<HTMLImageElement>('img.lead-image')!;
    expect(lead.getAttribute('width')).toBe('4');
    lead.dispatchEvent(new Event('error'));
    expect(source.dropLead).toHaveBeenCalled();
  });

  it('notes a paywall', () => {
    const element = mount((stub) => stub.paywall.set({ url: 'https://news.test/a' }));

    expect(element.querySelector('app-paywall-notice.paywall-note')).not.toBeNull();
  });

  it('puts the share disc before the view’s corner parts', () => {
    const element = mount();

    const corner = element.querySelector('.corner-actions')!;
    const share = corner.querySelector('app-share-button')!;
    const probe = corner.querySelector('.corner-probe')!;
    expect(share.compareDocumentPosition(probe) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it('jumps to a heading through the page', async () => {
    const element = mount((stub) =>
      stub.body.set({
        html: '<h2 id="one">One</h2><p>a</p><h2 id="two">Two</h2><p>b</p><h2 id="three">Three</h2>',
        pageUrl: 'https://news.test/a',
      }),
    );
    await Promise.resolve();
    rerender();
    const scroller = scrollerOf(fixture);
    const scrollTo = jest.fn();
    scroller.scrollTo = scrollTo;
    const heading = element.querySelector('.content h2') as HTMLElement;
    heading.getBoundingClientRect = () => ({ top: 700 }) as DOMRect;
    const restoreCss = stubCssEscape();

    try {
      element.querySelector<HTMLButtonElement>('.reader-panel__toggle')!.click();
      rerender();
      element.querySelector<HTMLButtonElement>('.toc-list button')!.click();
    } finally {
      restoreCss();
    }

    expect(scrollTo).toHaveBeenCalledWith(expect.objectContaining({ top: 700 }));
  });
});
