import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { HttpErrorResponse } from '@angular/common/http';
import { Subject } from 'rxjs';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { installMockResizeObserver } from '../../../../testing/resize-observer-testing';
import { aLinkCard } from '../../../../testing/entry-testing';
import { ReaderArticle, ReaderContent, ReaderFailure } from '../../models';
import { LinkCardDto } from '../../link-card/link-card';
import { ReaderContentService } from '../content/reader-content.service';
import { LinkCardRef } from '../../query/link-param';
import { ListAutoplayService } from '../../../core/preferences/list-autoplay.service';
import { ImageProxyService, ProxyOutcome } from '../../../shared/proxied-image/image-proxy.service';
import { LinkReaderComponent } from './link-reader.component';

const FIRST_CARD: LinkCardRef = { entryId: 7, position: 0 };
const SECOND_CARD: LinkCardRef = { entryId: 7, position: 1 };

const okContent = (over: Partial<ReaderArticle> = {}): ReaderArticle => ({
  status: 'ok',
  url: 'https://news.test/story',
  title: 'The extracted story',
  byline: null,
  siteName: 'News',
  contentHtml: '<p>LINKED PAGE</p>',
  excerpt: null,
  paywalled: false,
  originalHero: null,
  extractedAt: '',
  ...over,
});

const failedContent = (over: Partial<ReaderFailure> = {}): ReaderFailure => ({
  status: 'failed',
  url: 'https://news.test/story',
  reason: 'fetch',
  detail: 'HTTP 503',
  originalHero: { url: 'https://news.test/hero.jpg', width: 800, height: 450 },
  ...over,
});

describe('LinkReaderComponent', () => {
  let replies: Subject<ReaderContent>;
  let loadLinkCard: jest.Mock;
  let reloadLinkCard: jest.Mock;

  beforeEach(() => {
    installMockResizeObserver();
    replies = new Subject<ReaderContent>();
    loadLinkCard = jest.fn(() => replies);
    reloadLinkCard = jest.fn(() => replies);
    TestBed.configureTestingModule({
      imports: [LinkReaderComponent, provideTranslocoTesting()],
      providers: [
        { provide: ReaderContentService, useValue: { loadLinkCard, reloadLinkCard } },
        { provide: ListAutoplayService, useValue: { enabled: signal(false) } },
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
    fixture.componentRef.setInput('ref', FIRST_CARD);
    fixture.componentRef.setInput('card', card);
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

    reply(fixture, okContent());

    const element = elementOf(fixture);
    expect(element.querySelector('.content')!.textContent).toContain('LINKED PAGE');
    expect(element.querySelector('h1')!.textContent).toContain('The extracted story');
    expect(element.querySelector('article')!.getAttribute('aria-busy')).toBeNull();
    expect(element.querySelector('app-loading-overlay.shown')).toBeNull();
  });

  it('offers the original with the card image when the page cannot be read', () => {
    const fixture = mount();

    reply(fixture, failedContent());

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
    const fixture = mount();

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

  it('loads the card it is given, and the next one when that changes', () => {
    const fixture = mount();
    expect(loadLinkCard).toHaveBeenCalledWith(FIRST_CARD);

    fixture.componentRef.setInput('ref', SECOND_CARD);
    fixture.detectChanges();

    expect(loadLinkCard).toHaveBeenLastCalledWith(SECOND_CARD);
  });

  it('reloads the open card from the toolbar', () => {
    const fixture = mount();
    reply(fixture, okContent());

    elementOf(fixture).querySelector<HTMLButtonElement>('.nav button')!.click();

    expect(reloadLinkCard).toHaveBeenCalledWith(FIRST_CARD);
  });

  it('carries none of the entry reader chrome', () => {
    const fixture = mount();
    reply(fixture, okContent());

    const element = elementOf(fixture);
    expect(element.querySelector('app-link-cards')).toBeNull();
    expect(element.querySelector('app-entry-comments')).toBeNull();
    expect(element.querySelector('app-entry-actions')).toBeNull();
  });
});
