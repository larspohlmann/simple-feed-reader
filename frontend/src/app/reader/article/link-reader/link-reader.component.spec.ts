import { WritableSignal, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { HttpErrorResponse } from '@angular/common/http';
import { Subject } from 'rxjs';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { NO_EMBED_DETAILS } from '../../../../testing/embed-details-testing';
import { installMockResizeObserver } from '../../../../testing/resize-observer-testing';
import { A_READER_TOKEN, aLinkCard } from '../../../../testing/entry-testing';
import { ReaderContent } from '../../models';
import { aReaderArticle, aReaderFailure } from '../../../../testing/reader-content-testing';
import { LinkCardDto } from '../../link-card/link-card';
import { ReaderContentService } from '../content/reader-content.service';
import { SignedUrl } from '../../query/link-param';
import { ListAutoplayService } from '../../../core/preferences/list-autoplay.service';
import { ImageProxyService, ProxyOutcome } from '../../../shared/proxied-image/image-proxy.service';
import { LinkReaderComponent } from './link-reader.component';
import { LinkArticleSource } from './link-article-source.service';
import { ReaderRouteState } from '../../shell/reader-route-state.service';
import { provideAccountIdentity } from '../../../../testing/account-identity-testing';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { PostActions } from '../composer/post-actions.service';
import { ShareButtonComponent } from '../share/share-button.component';
import {
  ArticleSelection,
  SELECTION_SETTLE_MS,
} from '../selection-share/article-selection.service';

const FIRST_LINK: SignedUrl = { url: 'https://news.test/story', token: A_READER_TOKEN };
const SECOND_LINK: SignedUrl = { url: 'https://news.test/second', token: A_READER_TOKEN };

const linkedPage = aReaderArticle({
  url: 'https://news.test/story',
  title: 'The extracted story',
  siteName: 'News',
  contentHtml: '<p>LINKED PAGE</p>',
});

const linkedPageFailure = aReaderFailure({
  url: 'https://news.test/story',
  detail: 'HTTP 503',
});

describe('LinkReaderComponent', () => {
  let replies: Subject<ReaderContent>;
  let loadTarget: jest.Mock;
  let reloadTarget: jest.Mock;
  let routeState: {
    linkTarget: WritableSignal<SignedUrl | null>;
    openLinkCard: WritableSignal<LinkCardDto | null>;
  };

  beforeEach(() => {
    installMockResizeObserver();
    replies = new Subject<ReaderContent>();
    loadTarget = jest.fn(() => replies);
    reloadTarget = jest.fn(() => replies);
    routeState = { linkTarget: signal(null), openLinkCard: signal(null) };
    TestBed.configureTestingModule({
      imports: [LinkReaderComponent, provideTranslocoTesting()],
      providers: [
        provideAccountIdentity(),
        LinkArticleSource,
        { provide: ReaderRouteState, useValue: routeState },
        { provide: ReaderContentService, useValue: { load: loadTarget, reload: reloadTarget } },
        { provide: ListAutoplayService, useValue: { enabled: signal(false) } },
        { provide: SubscriptionsStore, useValue: { socialAccounts: signal([]) } },
        { provide: PostActions, useValue: {} },
        NO_EMBED_DETAILS,
        {
          provide: ImageProxyService,
          useValue: { recover: jest.fn<Promise<ProxyOutcome>, []>().mockResolvedValue('failed') },
        },
      ],
    });
  });

  function mount(
    card: LinkCardDto | null = aLinkCard({ title: 'A shared story', siteName: 'news.test' }),
    returnsTo: 'article' | 'list' = 'article',
  ): ComponentFixture<LinkReaderComponent> {
    const fixture = TestBed.createComponent(LinkReaderComponent);
    routeState.linkTarget.set(FIRST_LINK);
    routeState.openLinkCard.set(card);
    fixture.componentRef.setInput('target', FIRST_LINK);
    fixture.componentRef.setInput('returnsTo', returnsTo);
    fixture.detectChanges();
    return fixture;
  }

  function reply(fixture: ComponentFixture<LinkReaderComponent>, content: ReaderContent): void {
    replies.next(content);
    fixture.detectChanges();
  }

  const elementOf = (fixture: ComponentFixture<LinkReaderComponent>): HTMLElement =>
    fixture.nativeElement as HTMLElement;

  const openOriginalLinks = (fixture: ComponentFixture<LinkReaderComponent>) =>
    Array.from(elementOf(fixture).querySelectorAll<HTMLAnchorElement>('a')).filter((link) =>
      link.textContent?.includes('Open original'),
    );

  it('shows the card while the page loads', () => {
    const element = elementOf(mount());

    expect(element.querySelector('h1')!.textContent).toContain('A shared story');
    expect(element.querySelector('.meta')!.textContent).toContain('news.test');
    expect(element.querySelector('app-loading-overlay.shown')).not.toBeNull();
    expect(element.querySelector('article')!.getAttribute('aria-busy')).toBe('true');
  });

  it('shares the opened link', () => {
    const fixture = mount(aLinkCard({ url: 'https://news.test/story' }));

    const share = fixture.debugElement.query(By.directive(ShareButtonComponent))
      .componentInstance as ShareButtonComponent;
    expect(share.url()).toBe('https://news.test/story');
  });

  it('renders the extracted page', () => {
    const fixture = mount(null);

    reply(fixture, linkedPage);

    const element = elementOf(fixture);
    expect(element.querySelector('.content')!.textContent).toContain('LINKED PAGE');
    expect(element.querySelector('h1')!.textContent).toContain('The extracted story');
    expect(element.querySelector('article')!.getAttribute('aria-busy')).toBeNull();
    expect(element.querySelector('app-loading-overlay.shown')).toBeNull();
  });

  it('offers the original with the card image when the page cannot be read', () => {
    const fixture = mount(aLinkCard({ imageUrl: 'https://news.test/hero.jpg' }));

    reply(fixture, linkedPageFailure);

    const element = elementOf(fixture);
    expect(element.querySelector('app-warning-box.reader-fallback')!.textContent).toContain(
      'This page could not be read here.',
    );
    const links = openOriginalLinks(fixture);
    expect(links.length).toBeGreaterThan(0);
    for (const link of links) {
      expect(link.getAttribute('href')).toBe('https://news.test/story');
      expect(link.getAttribute('target')).toBe('_blank');
    }
    expect(element.querySelector('a.open-original')).not.toBeNull();
    expect(element.querySelector<HTMLImageElement>('.lead-image')!.getAttribute('src')).toBe(
      'https://news.test/hero.jpg',
    );
    expect(element.querySelector('.reader-error pre')!.textContent).toContain('HTTP 503');
  });

  it('shows the failure without an image when the request itself fails', () => {
    const fixture = mount(aLinkCard({ imageUrl: null }));

    replies.error(new HttpErrorResponse({ status: 404, statusText: 'Not Found' }));
    fixture.detectChanges();

    const element = elementOf(fixture);
    expect(element.querySelector('app-warning-box.reader-fallback')).not.toBeNull();
    expect(element.querySelector('.lead-image')).toBeNull();
    expect(element.querySelector('.reader-error pre')!.textContent).toContain('404');
    expect(element.querySelector('a.open-original')!.getAttribute('href')).toBe(
      'https://news.test/story',
    );
  });

  it('has no original to offer for an unlisted card whose request fails', () => {
    const fixture = mount(null);

    replies.error(new HttpErrorResponse({ status: 404, statusText: 'Not Found' }));
    fixture.detectChanges();

    expect(elementOf(fixture).querySelector('app-warning-box.reader-fallback')).not.toBeNull();
    expect(openOriginalLinks(fixture)).toEqual([]);
  });

  it('closes from the back button, labelled for the article it returns to', () => {
    const fixture = mount(aLinkCard(), 'article');
    const closes = jest.fn();
    fixture.componentInstance.close.subscribe(closes);
    const back = elementOf(fixture).querySelector<HTMLButtonElement>('button.close')!;

    back.click();

    expect(closes).toHaveBeenCalledTimes(1);
    expect(back.getAttribute('aria-label')).toBe('Back to article');
  });

  it('labels the back button for the list when it returns there', () => {
    const fixture = mount(aLinkCard(), 'list');

    expect(elementOf(fixture).querySelector('button.close')!.getAttribute('aria-label')).toBe(
      'Back to list',
    );
  });

  it('loads the link the route opens, and the next one when that changes', () => {
    const fixture = mount();
    expect(loadTarget).toHaveBeenCalledWith(FIRST_LINK);

    routeState.linkTarget.set(SECOND_LINK);
    fixture.componentRef.setInput('target', SECOND_LINK);
    fixture.detectChanges();

    expect(loadTarget).toHaveBeenLastCalledWith(SECOND_LINK);
  });

  it('reloads the open link from the toolbar', () => {
    const fixture = mount();
    reply(fixture, linkedPage);

    elementOf(fixture)
      .querySelector<HTMLButtonElement>('.nav button[aria-label="Reload article"]')!
      .click();

    expect(reloadTarget).toHaveBeenCalledWith(FIRST_LINK);
  });

  it('offers the reload after a failed load, and reloads the link from it', () => {
    const fixture = mount();
    expect(elementOf(fixture).querySelector('.nav button[aria-label="Reload article"]')).toBeNull();
    reply(fixture, linkedPageFailure);

    elementOf(fixture)
      .querySelector<HTMLButtonElement>('.nav button[aria-label="Reload article"]')!
      .click();

    expect(reloadTarget).toHaveBeenCalledWith(FIRST_LINK);
  });

  it('carries none of the entry reader chrome', () => {
    const fixture = mount();
    reply(fixture, linkedPage);

    const element = elementOf(fixture);
    expect(element.querySelector('app-link-cards')).toBeNull();
    expect(element.querySelector('app-entry-comments')).toBeNull();
    expect(element.querySelector('app-entry-actions')).toBeNull();
  });

  describe('selecting text to quote', () => {
    beforeEach(() => jest.useFakeTimers());

    afterEach(() => {
      document.getSelection()?.removeAllRanges();
      jest.useRealTimers();
    });

    function select(node: Node, text: string): void {
      const range = document.createRange();
      range.setStart(node, 0);
      range.setEnd(node, text.length);
      document.getSelection()!.removeAllRanges();
      document.getSelection()!.addRange(range);
      document.dispatchEvent(new Event('selectionchange'));
      jest.advanceTimersByTime(SELECTION_SETTLE_MS);
    }

    function collapse(): void {
      document.getSelection()!.removeAllRanges();
      document.dispatchEvent(new Event('selectionchange'));
      jest.advanceTimersByTime(SELECTION_SETTLE_MS);
    }

    function quoteOf(fixture: ComponentFixture<LinkReaderComponent>) {
      return fixture.debugElement.injector.get(ArticleSelection).quote();
    }

    it('quotes words selected in the page body', () => {
      const fixture = mount(null);
      reply(fixture, { ...linkedPage, contentHtml: '<p>Linked page words here</p>' });
      document.body.append(elementOf(fixture));
      const words = elementOf(fixture).querySelector('.content p')!.firstChild!;

      select(words, 'Linked page words');

      expect(quoteOf(fixture)?.text).toBe('Linked page words');
      elementOf(fixture).remove();
    });

    it('does not quote the page title', () => {
      const fixture = mount(null);
      reply(fixture, { ...linkedPage, contentHtml: '<p>Linked page words here</p>' });
      document.body.append(elementOf(fixture));
      const title = elementOf(fixture).querySelector('h1')!;

      select(title.firstChild!, title.textContent!);

      expect(quoteOf(fixture)).toBeNull();
      elementOf(fixture).remove();
    });

    it('remembers the quote while the same link is opened again', () => {
      const fixture = mount(null);
      reply(fixture, { ...linkedPage, contentHtml: '<p>Linked page words here</p>' });
      document.body.append(elementOf(fixture));
      select(elementOf(fixture).querySelector('.content p')!.firstChild!, 'Linked page words');
      collapse();

      fixture.componentRef.setInput('target', { ...FIRST_LINK });
      fixture.detectChanges();

      const selection = fixture.debugElement.injector.get(ArticleSelection);
      expect(selection.remembered()?.text).toBe('Linked page words');
      elementOf(fixture).remove();
    });

    it('does not quote words selected outside the page body', () => {
      const fixture = mount(aLinkCard({ siteName: 'News is the name' }));
      reply(fixture, { ...linkedPage, siteName: '' });
      document.body.append(elementOf(fixture));

      const barTitle = elementOf(fixture).querySelector('.bar-title')!;

      select(barTitle.firstChild!, barTitle.textContent!);

      expect(quoteOf(fixture)).toBeNull();
      elementOf(fixture).remove();
    });
  });
});
