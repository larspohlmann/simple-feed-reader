import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import { BehaviorSubject, of } from 'rxjs';
import { signal } from '@angular/core';
import { API_BASE_URL } from '../core/api';
import { AuthService } from '../core/auth.service';
import { ReaderShellComponent } from './reader-shell.component';

describe('ReaderShellComponent', () => {
  let ctrl: HttpTestingController;
  const qp = new BehaviorSubject(convertToParamMap({}));
  const auth = { user: signal({ email: 'a@b.c' }), loadMe: () => of({}), logout: jest.fn() };

  const subsBody = {
    subscriptions: [
      {
        id: 5,
        title: 'heise',
        customTitle: null,
        feedUrl: 'https://f/5',
        siteUrl: null,
        status: 'active',
        createdAt: 'x',
        tags: [],
        unreadCount: 2,
      },
    ],
  };
  const entry = {
    id: 1,
    title: 'e1',
    url: null,
    author: null,
    summary: 's',
    contentHtml: '<p>b</p>',
    publishedAt: '2026-07-22T11:00:00Z',
    createdAt: 'x',
    subscriptionId: 5,
    source: 'heise',
    isRead: false,
    isFavorite: false,
    isKept: false,
  };

  beforeEach(() => {
    qp.next(convertToParamMap({}));
    TestBed.configureTestingModule({
      imports: [ReaderShellComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: ActivatedRoute, useValue: { queryParamMap: qp.asObservable() } },
        { provide: AuthService, useValue: auth },
      ],
    });
    ctrl = TestBed.inject(HttpTestingController);
  });

  function boot() {
    const f = TestBed.createComponent(ReaderShellComponent);
    f.detectChanges(); // ngOnInit + initial effects
    ctrl.expectOne('https://api.test/api/subscriptions').flush(subsBody);
    ctrl
      .expectOne((r) => r.url === 'https://api.test/api/entries')
      .flush({
        entries: [entry],
        nextCursor: null,
      });
    f.detectChanges();
    return f;
  }

  it('renders header + sidebar and loads the initial list', () => {
    const el = boot().nativeElement as HTMLElement;
    expect(el.querySelector('app-reader-header')).not.toBeNull();
    expect(el.querySelector('app-sidebar')!.textContent).toContain('heise');
    // The shell's default layout is 'magazine'; the single loaded entry renders
    // as some magazine block (the first entry leads as a hero). Assert the list
    // mounted and rendered a block rather than pinning the exact tier, which is
    // planner-tuning-dependent.
    expect(el.querySelector('app-entry-list')).not.toBeNull();
    expect(el.querySelector('app-entry-hero, app-entry-compact, app-entry-row')).not.toBeNull();
  });

  it('marks the opened entry read', () => {
    const f = boot();
    qp.next(convertToParamMap({ entry: '1' }));
    f.detectChanges();
    const req = ctrl.expectOne('https://api.test/api/entries/1/state');
    expect(req.request.body).toEqual({ isRead: true });
    req.flush({
      state: { entryId: 1, isRead: true, isFavorite: false, isKept: false, readAt: 'x' },
    });
    expect(f.nativeElement.querySelector('app-reader-view')).not.toBeNull();
  });

  it('marks the opened entry read only once even when the PATCH fails', () => {
    const f = boot();
    qp.next(convertToParamMap({ entry: '1' }));
    f.detectChanges();
    const req = ctrl.expectOne('https://api.test/api/entries/1/state');
    expect(req.request.body).toEqual({ isRead: true });
    req.flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });
    f.detectChanges();
    // The entry is still unread (rollback), but the effect must NOT re-fire a PATCH.
    ctrl.expectNone((r) => r.url.endsWith('/entries/1/state'));
    ctrl.verify();
  });

  it('reloads entries when the selection changes', () => {
    const f = boot();
    qp.next(convertToParamMap({ subscription: '5' }));
    f.detectChanges();
    ctrl
      .expectOne((r) => r.params.get('subscription') === '5')
      .flush({ entries: [], nextCursor: null });
    f.detectChanges();
    expect(f.nativeElement.querySelector('.empty')).not.toBeNull();
  });
});
