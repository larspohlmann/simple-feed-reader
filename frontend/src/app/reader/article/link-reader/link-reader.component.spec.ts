import { WritableSignal, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
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
});
