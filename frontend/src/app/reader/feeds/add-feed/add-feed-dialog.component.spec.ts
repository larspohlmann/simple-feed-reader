import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { DialogRef } from '@angular/cdk/dialog';
import { provideRouter } from '@angular/router';
import { API_BASE_URL } from '../../../core/api';
import { AddFeedDialogComponent } from './add-feed-dialog.component';

describe('AddFeedDialogComponent', () => {
  let ctrl: HttpTestingController;
  const close = jest.fn();
  beforeEach(() => {
    close.mockReset();
    TestBed.configureTestingModule({
      imports: [AddFeedDialogComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: DialogRef, useValue: { close } },
      ],
    });
    ctrl = TestBed.inject(HttpTestingController);
  });

  afterEach(() => ctrl.verify());

  function create() {
    const fixture = TestBed.createComponent(AddFeedDialogComponent);
    fixture.detectChanges();
    // ngOnInit loads the full tag list to populate the picker.
    ctrl.expectOne('https://api.test/api/tags').flush({
      tags: [
        { id: 1, name: 'Tech', color: null, icon: null, position: 0 },
        { id: 2, name: 'News', color: null, icon: null, position: 1 },
      ],
    });
    fixture.detectChanges();
    return fixture;
  }

  it('closes with the created subscription', () => {
    const fixture = create();
    fixture.componentInstance.form.setValue({ url: 'https://example.com/feed' });
    fixture.componentInstance.submit();
    ctrl
      .expectOne('https://api.test/api/subscriptions')
      .flush({ subscription: { id: 9 } }, { status: 201, statusText: 'Created' });
    expect(close).toHaveBeenCalledWith({ id: 9 });
  });

  it('reports that a search is running while the subscribe is in flight', () => {
    // Discovery can spend seconds probing a site that hides its feed, so the
    // dialog has to say something is happening — a disabled button does not.
    const fixture = create();
    fixture.componentInstance.form.setValue({ url: 'https://slow.example' });
    fixture.componentInstance.submit();
    fixture.detectChanges();

    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('.searching')?.textContent).toContain('Looking for a feed');
    expect(element.querySelector('.searching app-spinner')).toBeTruthy();

    ctrl
      .expectOne('https://api.test/api/subscriptions')
      .flush({ candidates: [], scrapeFailureReason: 'blocked' });
    fixture.detectChanges();
    expect(element.querySelector('.searching')).toBeNull();
  });

  it('names Apple Podcasts share links beside the URL field', () => {
    const fixture = create();

    expect(
      (fixture.nativeElement as HTMLElement).querySelector('app-field .hint')!.textContent,
    ).toContain('Apple Podcasts');
  });

  it('renders the tag picker and sends the checked tag ids on submit', () => {
    const fixture = create();
    const pills = (fixture.nativeElement as HTMLElement).querySelectorAll('button.tag-pill');
    expect(pills.length).toBe(2);

    fixture.componentInstance.toggleTag(2);
    fixture.componentInstance.form.setValue({ url: 'https://example.com/feed' });
    fixture.componentInstance.submit();

    const testRequest = ctrl.expectOne('https://api.test/api/subscriptions');
    expect(testRequest.request.body).toEqual({ url: 'https://example.com/feed', tagIds: [2] });
    testRequest.flush({ subscription: { id: 9 } }, { status: 201, statusText: 'Created' });
    expect(close).toHaveBeenCalledWith({ id: 9 });
  });

  it('carries the selected tags through to a picked candidate', () => {
    const fixture = create();
    fixture.componentInstance.toggleTag(1);
    fixture.componentInstance.form.setValue({ url: 'https://example.com' });
    fixture.componentInstance.submit();
    // The first POST resolves to a SINGLE candidate, which auto-expands and
    // previews — tags are not applied yet, they ride along on the follow-up pick.
    ctrl
      .expectOne('https://api.test/api/subscriptions')
      .flush({ candidates: [{ url: 'https://f/rss', title: 'RSS', format: 'rss' }] });
    ctrl
      .expectOne((request) => request.url.endsWith('/api/feeds/preview'))
      .flush({
        feed: { title: 'RSS', itemCount: 1, content: 'full', hasImages: false, items: [] },
      });
    fixture.detectChanges();

    const card = (fixture.nativeElement as HTMLElement).querySelector('.card')!;
    (card.querySelector('.subscribe') as HTMLButtonElement).click();
    const subTestRequest = ctrl.expectOne('https://api.test/api/subscriptions');
    expect(subTestRequest.request.body).toEqual({ url: 'https://f/rss', tagIds: [1] });
    subTestRequest.flush({ subscription: { id: 4 } }, { status: 201, statusText: 'Created' });
    expect(close).toHaveBeenCalledWith({ id: 4 });
  });

  it('auto-expands and previews a single candidate', () => {
    const fixture = create();
    fixture.componentInstance.form.setValue({ url: 'https://example.com' });
    fixture.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/subscriptions').flush({
      candidates: [{ url: 'https://f/rss', title: 'RSS', format: 'rss' }],
    });
    fixture.detectChanges();
    expect(fixture.componentInstance.candidates().length).toBe(1);

    const rssTestRequest = ctrl.expectOne(
      (request) =>
        request.url.endsWith('/api/feeds/preview') && request.body.url === 'https://f/rss',
    );
    // XML candidates preview with the bare URL — only scraped ones carry a format.
    expect(rssTestRequest.request.body).toEqual({ url: 'https://f/rss' });

    rssTestRequest.flush({
      feed: {
        title: 'RSS Feed',
        itemCount: 2,
        content: 'full',
        hasImages: true,
        items: [
          {
            title: 'First headline',
            url: 'https://f/rss/1',
            publishedAt: null,
            author: null,
            summary: 'snip',
            imageUrl: 'https://img.example/a.jpg',
            imageWidth: 800,
            imageHeight: 600,
          },
          {
            title: 'Second headline',
            url: 'https://f/rss/2',
            publishedAt: null,
            author: null,
            summary: 'snip2',
            imageUrl: null,
            imageWidth: null,
            imageHeight: null,
          },
        ],
      },
    });
    fixture.detectChanges();

    const cards = (fixture.nativeElement as HTMLElement).querySelectorAll('.card');
    expect(cards.length).toBe(1);
    // The footer "Add" submit is hidden once cards (each with Subscribe) show.
    expect(
      (fixture.nativeElement as HTMLElement).querySelector('button[type="submit"]'),
    ).toBeNull();
    const rssCard = cards[0];
    expect(rssCard.textContent).toContain('Full text');
    expect(rssCard.textContent).toContain('With images');
    expect(rssCard.textContent).toContain('First headline');
    expect(rssCard.querySelector('.badge.format')?.textContent?.trim()).toBe('RSS');
    // Exactly one preview request was made — a second unmatched one here would
    // fail afterEach's ctrl.verify().
    const rows = (fixture.nativeElement as HTMLElement).querySelectorAll('app-preview-entry-row');
    expect(rows.length).toBeGreaterThan(0);

    (rssCard.querySelector('.subscribe') as HTMLButtonElement).click();
    const subTestRequest = ctrl.expectOne('https://api.test/api/subscriptions');
    expect(subTestRequest.request.body).toEqual({ url: 'https://f/rss' });
    subTestRequest.flush({ subscription: { id: 3 } }, { status: 201, statusText: 'Created' });
    expect(close).toHaveBeenCalledWith({ id: 3 });
  });

  it('auto-previews the first candidate and previews the rest lazily on click', () => {
    const fixture = create();
    fixture.componentInstance.form.setValue({ url: 'https://example.com' });
    fixture.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/subscriptions').flush({
      candidates: [
        { url: 'https://f/rss', title: 'RSS', format: 'rss' },
        { url: 'https://f/atom', title: 'ATOM', format: 'atom' },
      ],
    });
    fixture.detectChanges();
    expect(fixture.componentInstance.candidates().length).toBe(2);

    // The first candidate auto-expands, so its preview is fetched immediately;
    // the second is not previewed until the user opens it.
    const rssTestRequest = ctrl.expectOne(
      (request) =>
        request.url.endsWith('/api/feeds/preview') && request.body.url === 'https://f/rss',
    );
    ctrl.expectNone(
      (request) =>
        request.url.endsWith('/api/feeds/preview') && request.body.url === 'https://f/atom',
    );
    rssTestRequest.flush({
      feed: {
        title: 'RSS Feed',
        itemCount: 2,
        content: 'full',
        hasImages: true,
        items: [
          {
            title: 'First headline',
            url: 'https://f/rss/1',
            publishedAt: null,
            author: null,
            summary: 'snip',
            imageUrl: 'https://img.example/a.jpg',
            imageWidth: 800,
            imageHeight: 600,
          },
        ],
      },
    });
    fixture.detectChanges();

    const cards = (fixture.nativeElement as HTMLElement).querySelectorAll('.card');
    expect(cards.length).toBe(2);
    const [rssCard, atomCard] = Array.from(cards);
    expect(rssCard.textContent).toContain('First headline');
    expect(rssCard.querySelector('app-preview-entry-row')).not.toBeNull();
    // The second candidate has not been fetched or rendered yet.
    expect(atomCard.querySelector('app-preview-entry-row')).toBeNull();

    // Opening the second candidate via its Preview pill fetches its preview
    // now (and collapses the first, since only one expands at a time).
    (atomCard.querySelector('.preview-toggle') as HTMLButtonElement).click();
    fixture.detectChanges();
    const atomTestRequest = ctrl.expectOne(
      (request) =>
        request.url.endsWith('/api/feeds/preview') && request.body.url === 'https://f/atom',
    );
    atomTestRequest.flush({
      feed: {
        title: 'ATOM Feed',
        itemCount: 1,
        content: 'full',
        hasImages: false,
        items: [
          {
            title: 'Second headline',
            url: 'https://f/atom/1',
            publishedAt: null,
            author: null,
            summary: 'snip',
            imageUrl: null,
            imageWidth: null,
            imageHeight: null,
          },
        ],
      },
    });
    fixture.detectChanges();
    expect(atomCard.querySelector('app-preview-entry-row')).not.toBeNull();
  });

  it('labels a scraped candidate and subscribes/previews with the scraped format', () => {
    const fixture = create();
    fixture.componentInstance.form.setValue({ url: 'https://page.example/' });
    fixture.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/subscriptions').flush({
      candidates: [{ url: 'https://page.example/', title: 'Page', format: 'scraped' }],
    });
    fixture.detectChanges();

    const previewTestRequest = ctrl.expectOne((request) =>
      request.url.endsWith('/api/feeds/preview'),
    );
    expect(previewTestRequest.request.body).toEqual({
      url: 'https://page.example/',
      format: 'scraped',
    });
    previewTestRequest.flush('x', { status: 500, statusText: 'err' });
    fixture.detectChanges();

    const card = (fixture.nativeElement as HTMLElement).querySelector('.card')!;
    expect(card.querySelector('.badge.format')?.textContent?.trim()).toBe('Scraped');
    expect(card.querySelector('.scraped-hint')?.textContent).toContain('article list');

    (card.querySelector('.subscribe') as HTMLButtonElement).click();
    const subTestRequest = ctrl.expectOne('https://api.test/api/subscriptions');
    expect(subTestRequest.request.body).toEqual({
      url: 'https://page.example/',
      format: 'scraped',
    });
    subTestRequest.flush({ subscription: { id: 7 } }, { status: 201, statusText: 'Created' });
    expect(close).toHaveBeenCalledWith({ id: 7 });
  });

  it('detects the dialog dropping the selected WordPress title when it subscribes', () => {
    const fixture = create();
    fixture.componentInstance.form.setValue({ url: 'https://wp.example/' });
    fixture.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/subscriptions').flush({
      candidates: [
        { url: 'https://wp.example/wp-json/wp/v2/posts', title: 'WP', format: 'wp-json' },
      ],
    });
    fixture.detectChanges();

    const previewTestRequest = ctrl.expectOne((request) =>
      request.url.endsWith('/api/feeds/preview'),
    );
    expect(previewTestRequest.request.body).toEqual({
      url: 'https://wp.example/wp-json/wp/v2/posts',
      format: 'wp-json',
    });
    previewTestRequest.flush({
      feed: { title: 'WP', itemCount: 5, content: 'full', hasImages: true, items: [] },
    });
    fixture.detectChanges();

    const card = (fixture.nativeElement as HTMLElement).querySelector('.card')!;
    expect(card.querySelector('.badge.format')?.textContent?.trim()).toBe('WordPress');

    (card.querySelector('.subscribe') as HTMLButtonElement).click();
    const subTestRequest = ctrl.expectOne('https://api.test/api/subscriptions');
    expect(subTestRequest.request.body).toEqual({
      url: 'https://wp.example/wp-json/wp/v2/posts',
      format: 'wp-json',
      title: 'WP',
    });
    subTestRequest.flush({ subscription: { id: 8 } }, { status: 201, statusText: 'Created' });
    expect(close).toHaveBeenCalledWith({ id: 8 });
  });

  it('marks a scraped candidate as experimental', () => {
    const fixture = create();
    fixture.componentInstance.form.setValue({ url: 'https://page.example/' });
    fixture.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/subscriptions').flush({
      candidates: [{ url: 'https://page.example/', title: 'Page', format: 'scraped' }],
    });
    fixture.detectChanges();
    ctrl
      .expectOne((request) => request.url.endsWith('/api/feeds/preview'))
      .flush('x', { status: 500, statusText: 'err' });
    fixture.detectChanges();

    const badges = (fixture.nativeElement as HTMLElement).querySelectorAll('.badge');
    const text = Array.from(badges, (badge) => (badge as HTMLElement).textContent?.trim());
    expect(text).toContain('Experimental');
  });

  it('warns when the site blocks scraping and hides the footer submit', () => {
    const fixture = create();
    fixture.componentInstance.form.setValue({ url: 'https://blocked.example' });
    fixture.componentInstance.submit();
    ctrl
      .expectOne('https://api.test/api/subscriptions')
      .flush({ candidates: [], scrapeFailureReason: 'blocked' });
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('.warn')?.textContent).toContain('blocks automated access');
    expect(element.querySelector('button.subscribe')).toBeNull();
    // Nothing can be subscribed here, so the footer "Add" would be a dead end.
    expect(element.querySelector('button[type="submit"]')).toBeNull();
    expect(close).not.toHaveBeenCalled();
  });

  it('tells the user to wait when the site is rationing requests', () => {
    // "It blocks automated access" would be wrong and final; a 429 asks for
    // patience, and the retry is the user's next move.
    const fixture = create();
    fixture.componentInstance.form.setValue({ url: 'https://busy.example' });
    fixture.componentInstance.submit();
    ctrl
      .expectOne('https://api.test/api/subscriptions')
      .flush({ candidates: [], scrapeFailureReason: 'throttled' });
    fixture.detectChanges();

    expect((fixture.nativeElement as HTMLElement).querySelector('.warn')?.textContent).toContain(
      'limiting requests',
    );
  });

  it('says the feed is too large rather than unreachable', () => {
    const fixture = create();
    fixture.componentInstance.form.setValue({ url: 'https://podcast.example/feed' });
    fixture.componentInstance.submit();
    ctrl
      .expectOne('https://api.test/api/subscriptions')
      .flush({ candidates: [], scrapeFailureReason: 'too_large' });
    fixture.detectChanges();

    expect((fixture.nativeElement as HTMLElement).querySelector('.warn')?.textContent).toContain(
      'larger than the reader accepts',
    );
  });

  it('shows a generic warning for a scrape-failure reason it does not recognise', () => {
    // The backend reason set is open, so a newer server may send a reason this
    // build has never heard of; it must still warn, not render an empty box.
    const fixture = create();
    fixture.componentInstance.form.setValue({ url: 'https://weird.example' });
    fixture.componentInstance.submit();
    ctrl
      .expectOne('https://api.test/api/subscriptions')
      .flush({ candidates: [], scrapeFailureReason: 'quantum_flux' });
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    const warn = element.querySelector('.warn');
    expect(warn?.textContent?.trim()).toBe("This page can't be subscribed.");
    expect(element.querySelector('button.subscribe')).toBeNull();
    expect(element.querySelector('button[type="submit"]')).toBeNull();
  });

  it('clears the scrape-failure warning once the URL is edited', () => {
    const fixture = create();
    fixture.componentInstance.form.setValue({ url: 'https://blocked.example' });
    fixture.componentInstance.submit();
    ctrl
      .expectOne('https://api.test/api/subscriptions')
      .flush({ candidates: [], scrapeFailureReason: 'blocked' });
    fixture.detectChanges();
    expect((fixture.nativeElement as HTMLElement).querySelector('.warn')).toBeTruthy();

    fixture.componentInstance.form.setValue({ url: 'https://other.example' });
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('.warn')).toBeNull();
    expect(element.querySelector('button[type="submit"]')).toBeTruthy();
  });

  it('drops stale candidate cards when a re-search then fails to scrape', () => {
    const fixture = create();
    // First search finds feeds — candidate cards with Subscribe buttons render.
    fixture.componentInstance.form.setValue({ url: 'https://has-feeds.example' });
    fixture.componentInstance.submit();
    ctrl
      .expectOne('https://api.test/api/subscriptions')
      .flush({ candidates: [{ url: 'https://f/rss', title: 'RSS', format: 'rss' }] });
    ctrl
      .expectOne((request) => request.url.endsWith('/api/feeds/preview'))
      .flush({
        feed: { title: 'RSS', itemCount: 3, content: 'summary', hasImages: false, items: [] },
      });
    fixture.detectChanges();
    expect((fixture.nativeElement as HTMLElement).querySelector('button.subscribe')).toBeTruthy();

    // Re-search a different URL that cannot be scraped: the old cards (and their
    // Subscribe buttons) must be gone, leaving only the warning.
    fixture.componentInstance.form.setValue({ url: 'https://blocked.example' });
    fixture.componentInstance.submit();
    ctrl
      .expectOne('https://api.test/api/subscriptions')
      .flush({ candidates: [], scrapeFailureReason: 'blocked' });
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('button.subscribe')).toBeNull();
    expect(element.querySelector('.card')).toBeNull();
    expect(element.querySelector('.warn')?.textContent).toContain('blocks automated access');
  });

  it("shows the backend's problem detail when a preview fails", () => {
    const fixture = create();
    fixture.componentInstance.form.setValue({ url: 'https://page.example/' });
    fixture.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/subscriptions').flush({
      candidates: [{ url: 'https://page.example/', title: 'Page', format: 'scraped' }],
    });
    fixture.detectChanges();

    ctrl
      .expectOne((request) => request.url.endsWith('/api/feeds/preview'))
      .flush(
        {
          type: 'feed_preview_failed',
          title: 'Feed preview failed',
          status: 422,
          detail: 'No article list was detected on the page.',
        },
        { status: 422, statusText: 'Unprocessable' },
      );
    fixture.detectChanges();

    const card = (fixture.nativeElement as HTMLElement).querySelector('.card')!;
    expect(card.textContent).toContain('No article list was detected on the page.');
    expect(card.textContent).not.toContain('Preview unavailable');
  });

  it('falls back to a generic line when a failed preview carries no detail', () => {
    const fixture = create();
    fixture.componentInstance.form.setValue({ url: 'https://f.example/' });
    fixture.componentInstance.submit();
    ctrl
      .expectOne('https://api.test/api/subscriptions')
      .flush({ candidates: [{ url: 'https://f/rss', title: 'RSS', format: 'rss' }] });
    fixture.detectChanges();

    ctrl
      .expectOne((request) => request.url.endsWith('/api/feeds/preview'))
      .flush('x', {
        status: 500,
        statusText: 'err',
      });
    fixture.detectChanges();

    expect((fixture.nativeElement as HTMLElement).querySelector('.card')!.textContent).toContain(
      'Preview unavailable',
    );
  });

  it('shows an empty state when no candidates are found', () => {
    const fixture = create();
    fixture.componentInstance.form.setValue({ url: 'https://example.com' });
    fixture.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/subscriptions').flush({ candidates: [] });
    fixture.detectChanges();
    expect(
      (fixture.nativeElement as HTMLElement).querySelector('.fields > .hint')!.textContent,
    ).toContain('No feeds found');
    expect(close).not.toHaveBeenCalled();
  });

  it('says nothing about scraping when scraping is off and nothing was found', () => {
    // With scraping off, the backend sends candidates: [] and no
    // scrapeFailureReason — this pins the plain "no feeds found" wording so a
    // future refactor cannot quietly reintroduce a scraping mention here.
    const fixture = create();
    fixture.componentInstance.form.setValue({ url: 'https://example.com' });
    fixture.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/subscriptions').flush({ candidates: [] });
    fixture.detectChanges();

    const text = (fixture.nativeElement as HTMLElement).textContent!.toLowerCase();
    expect(text).toContain('no feeds found');
    expect(text).not.toContain('scrap');
  });

  it('shows a field error on 422', () => {
    const fixture = create();
    fixture.componentInstance.form.setValue({ url: 'not-a-url' });
    fixture.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/subscriptions').flush(
      {
        type: 'validation_error',
        title: 'x',
        status: 422,
        errors: { url: ['This value is not a valid URL.'] },
      },
      { status: 422, statusText: 'Unprocessable' },
    );
    expect(fixture.componentInstance.error()).toContain('valid URL');
    expect(close).not.toHaveBeenCalled();
  });
});
