import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { DialogRef } from '@angular/cdk/dialog';
import { API_BASE_URL } from '../../core/api';
import { AddFeedDialogComponent } from './add-feed-dialog.component';

describe('AddFeedDialogComponent', () => {
  let ctrl: HttpTestingController;
  const close = jest.fn();
  beforeEach(() => {
    close.mockReset();
    TestBed.configureTestingModule({
      imports: [AddFeedDialogComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: DialogRef, useValue: { close } },
      ],
    });
    ctrl = TestBed.inject(HttpTestingController);
  });

  function create() {
    const f = TestBed.createComponent(AddFeedDialogComponent);
    f.detectChanges();
    return f;
  }

  it('closes with the created subscription', () => {
    const f = create();
    f.componentInstance.form.setValue({ url: 'https://example.com/feed' });
    f.componentInstance.submit();
    ctrl
      .expectOne('https://api.test/api/subscriptions')
      .flush({ subscription: { id: 9 } }, { status: 201, statusText: 'Created' });
    expect(close).toHaveBeenCalledWith({ id: 9 });
  });

  it('lists candidates as cards with previews and subscribes via the Subscribe button', () => {
    const f = create();
    f.componentInstance.form.setValue({ url: 'https://example.com' });
    f.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/subscriptions').flush({
      candidates: [
        { url: 'https://f/rss', title: 'RSS' },
        { url: 'https://f/atom', title: 'ATOM' },
      ],
    });
    f.detectChanges();
    expect(f.componentInstance.candidates().length).toBe(2);

    const rssReq = ctrl.expectOne(
      (r) => r.url.endsWith('/api/feeds/preview') && r.body.url === 'https://f/rss',
    );
    const atomReq = ctrl.expectOne(
      (r) => r.url.endsWith('/api/feeds/preview') && r.body.url === 'https://f/atom',
    );

    rssReq.flush({
      feed: {
        title: 'RSS Feed',
        itemCount: 2,
        content: 'full',
        hasImages: true,
        items: [
          {
            title: 'First headline',
            publishedAt: null,
            author: null,
            hasImage: true,
            textLength: 500,
            snippet: 'snip',
          },
          {
            title: 'Second headline',
            publishedAt: null,
            author: null,
            hasImage: false,
            textLength: 300,
            snippet: 'snip2',
          },
        ],
      },
    });
    atomReq.flush('x', { status: 500, statusText: 'err' });
    f.detectChanges();

    const cards = (f.nativeElement as HTMLElement).querySelectorAll('.card');
    expect(cards.length).toBe(2);
    const [rssCard, atomCard] = Array.from(cards);
    expect(rssCard.textContent).toContain('Full text');
    expect(rssCard.textContent).toContain('With images');
    expect(rssCard.textContent).toContain('First headline');
    expect(atomCard.textContent).toContain('Preview unavailable');
    expect(atomCard.querySelector('.subscribe')).toBeTruthy();

    (rssCard.querySelector('.subscribe') as HTMLButtonElement).click();
    const subReq = ctrl.expectOne('https://api.test/api/subscriptions');
    expect(subReq.request.body).toEqual({ url: 'https://f/rss' });
    subReq.flush({ subscription: { id: 3 } }, { status: 201, statusText: 'Created' });
    expect(close).toHaveBeenCalledWith({ id: 3 });
  });

  it('shows an empty state when no candidates are found', () => {
    const f = create();
    f.componentInstance.form.setValue({ url: 'https://example.com' });
    f.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/subscriptions').flush({ candidates: [] });
    f.detectChanges();
    expect((f.nativeElement as HTMLElement).querySelector('.hint')!.textContent).toContain(
      'No feeds found',
    );
    expect(close).not.toHaveBeenCalled();
  });

  it('shows a field error on 422', () => {
    const f = create();
    f.componentInstance.form.setValue({ url: 'not-a-url' });
    f.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/subscriptions').flush(
      {
        type: 'validation_error',
        title: 'x',
        status: 422,
        errors: { url: ['This value is not a valid URL.'] },
      },
      { status: 422, statusText: 'Unprocessable' },
    );
    expect(f.componentInstance.error()).toContain('valid URL');
    expect(close).not.toHaveBeenCalled();
  });
});
