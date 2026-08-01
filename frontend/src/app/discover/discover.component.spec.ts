import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideRouter, Router } from '@angular/router';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { API_BASE_URL } from '../core/api';
import { CatalogStore } from './catalog.store';
import { CatalogCategoryDto } from './catalog.models';
import { DiscoverComponent } from './discover.component';

const CATALOG = {
  categories: [
    {
      id: 1,
      key: 'technology',
      name: 'Technology',
      icon: 'memory',
      color: '#3b82f6',
      feeds: [
        {
          id: 10,
          title: 'The Verge',
          description: 'Tech',
          siteUrl: null,
          faviconUrl: '/f/10',
          subscribed: false,
        },
        {
          id: 11,
          title: 'Wired',
          description: null,
          siteUrl: null,
          faviconUrl: '/f/11',
          subscribed: true,
        },
      ],
    },
  ],
};

/** Two categories with two pickable feeds between them, which CATALOG cannot
 *  offer — it has exactly one feed nobody is subscribed to. */
const MULTI = {
  categories: [
    {
      id: 1,
      key: 'technology',
      name: 'Technology',
      icon: 'memory',
      color: '#3b82f6',
      feeds: [
        {
          id: 10,
          title: 'The Verge',
          description: null,
          siteUrl: null,
          faviconUrl: '/f/10',
          subscribed: false,
        },
        {
          id: 11,
          title: 'Wired',
          description: null,
          siteUrl: null,
          faviconUrl: '/f/11',
          subscribed: false,
        },
      ],
    },
    {
      id: 2,
      key: 'science',
      name: 'Science',
      icon: 'science',
      color: '#22c55e',
      feeds: [
        {
          id: 20,
          title: 'Nature',
          description: null,
          siteUrl: null,
          faviconUrl: '/f/20',
          subscribed: false,
        },
      ],
    },
  ],
};

describe('DiscoverComponent', () => {
  let fixture: ComponentFixture<DiscoverComponent>;
  let http: HttpTestingController;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [DiscoverComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(DiscoverComponent);
    http = TestBed.inject(HttpTestingController);
    fixture.detectChanges();
    http.expectOne('https://api.test/api/catalog').flush(CATALOG);
    fixture.detectChanges();
  });

  afterEach(() => http.verify());

  /** The catalog store is app-wide, so a test needing a different catalog has to
   *  reset it and mount its own component — the same path the scroll-spy test
   *  below uses to reach the genuine first-visit state. */
  const mountWith = (catalog: {
    categories: CatalogCategoryDto[];
  }): ComponentFixture<DiscoverComponent> => {
    TestBed.inject(CatalogStore).invalidate();
    const local = TestBed.createComponent(DiscoverComponent);
    local.detectChanges();
    http.expectOne('https://api.test/api/catalog').flush(catalog);
    local.detectChanges();
    return local;
  };

  const pick = (target: ComponentFixture<DiscoverComponent>, ...cardIndexes: number[]): string => {
    const cards: HTMLButtonElement[] = Array.from(
      target.nativeElement.querySelectorAll('[data-testid="catalog-feed"] button'),
    );
    cardIndexes.forEach((index) => cards[index].click());
    target.detectChanges();

    return target.nativeElement.querySelector('.cnt').textContent.trim();
  };

  // The footer counts the SELECTION, and the sentence has to say so: rendered as
  // bare numbers it read "0 feeds in 0 categories" under a full picker, which
  // parses as a claim that the catalog is empty (#146).
  it('names the selection rather than counting an empty one', () => {
    expect(fixture.nativeElement.querySelector('.cnt').textContent.trim()).toBe(
      'Nothing selected yet',
    );
  });

  it('inflects the summary for one feed', () => {
    expect(pick(fixture, 0)).toBe('1 feed selected');
  });

  it('inflects the summary for several feeds in one category', () => {
    expect(pick(mountWith(MULTI), 0, 1)).toBe('2 feeds selected in 1 category');
  });

  it('inflects the summary for several feeds across categories', () => {
    expect(pick(mountWith(MULTI), 0, 2)).toBe('2 feeds selected in 2 categories');
  });

  it('renders every category and its feeds', () => {
    const cards = fixture.nativeElement.querySelectorAll('[data-testid="catalog-feed"]');
    expect(cards).toHaveLength(2);
  });

  it('renders an already-subscribed feed as pressed and disabled', () => {
    const cards: HTMLButtonElement[] = Array.from(
      fixture.nativeElement.querySelectorAll('[data-testid="catalog-feed"] button'),
    );
    expect(cards[1].getAttribute('aria-pressed')).toBe('true');
    expect(cards[1].disabled).toBe(true);
  });

  it('submits only the newly picked ids', () => {
    const cards: HTMLButtonElement[] = Array.from(
      fixture.nativeElement.querySelectorAll('[data-testid="catalog-feed"] button'),
    );
    cards[0].click();
    fixture.detectChanges();

    fixture.nativeElement.querySelector('[data-testid="subscribe"]').click();

    const req = http.expectOne('https://api.test/api/onboarding/subscribe');
    expect(req.request.body).toEqual({ catalogFeedIds: [10] });
    req.flush({ subscribed: 1, skipped: 0, skippedOverLimit: 0, tagsCreated: [] });

    // The success handler reloads subscriptions and tags; drain those follow-on
    // GETs so afterEach's http.verify() sees no outstanding requests.
    http.match('https://api.test/api/subscriptions').forEach((r) => r.flush({ subscriptions: [] }));
    http.match('https://api.test/api/tags').forEach((r) => r.flush({ tags: [] }));
  });

  it('navigates into the reader after a successful subscribe', async () => {
    const router = TestBed.inject(Router);
    const navigate = jest.spyOn(router, 'navigate').mockResolvedValue(true);

    const cards: HTMLButtonElement[] = Array.from(
      fixture.nativeElement.querySelectorAll('[data-testid="catalog-feed"] button'),
    );
    cards[0].click();
    fixture.detectChanges();
    fixture.nativeElement.querySelector('[data-testid="subscribe"]').click();

    http
      .expectOne('https://api.test/api/onboarding/subscribe')
      .flush({ subscribed: 1, skipped: 0, skippedOverLimit: 0, tagsCreated: [] });

    // The success handler reloads subscriptions and tags; drain those follow-on
    // GETs so afterEach's http.verify() sees no outstanding requests.
    http.match('https://api.test/api/subscriptions').forEach((r) => r.flush({ subscriptions: [] }));
    http.match('https://api.test/api/tags').forEach((r) => r.flush({ tags: [] }));

    await fixture.whenStable();

    expect(navigate).toHaveBeenCalledWith(['/']);
  });

  it('wires the scroll-spy to the sections that render after the catalog loads', () => {
    // The catalog arrives async, so the sections signal is empty on the first
    // frame. Prove the observer re-observes once they render — a wiring that
    // ran only in ngAfterViewInit would observe nothing on this real path.
    const observed: HTMLElement[] = [];
    let capturedCallback: IntersectionObserverCallback | null = null;
    const realIntersectionObserver = globalThis.IntersectionObserver;
    globalThis.IntersectionObserver = class {
      constructor(callback: IntersectionObserverCallback) {
        capturedCallback = callback;
      }
      readonly observe = jest.fn((element: Element) => {
        observed.push(element as HTMLElement);
      });
      readonly unobserve = jest.fn();
      readonly disconnect = jest.fn();
      takeRecords(): [] {
        return [];
      }
    } as unknown as typeof IntersectionObserver;

    try {
      // Force the genuine first-visit path: an UNRESOLVED store, so categories
      // (and therefore the sections) are empty when the component mounts and
      // only fill after the catalog GET resolves. A wiring that ran only in
      // ngAfterViewInit would observe the empty first frame and never re-observe.
      TestBed.inject(CatalogStore).invalidate();
      const local = TestBed.createComponent(DiscoverComponent);
      local.detectChanges();
      expect(observed).toHaveLength(0);

      http.expectOne('https://api.test/api/catalog').flush(CATALOG);
      local.detectChanges();

      expect(observed).toHaveLength(1);
      expect(observed[0].dataset['categoryId']).toBe('1');

      // Drive an intersection through the captured callback and confirm it
      // flows into ActiveCategory.
      capturedCallback!(
        [{ isIntersecting: true, target: observed[0] } as unknown as IntersectionObserverEntry],
        {} as IntersectionObserver,
      );
      expect(local.componentInstance.active.activeId()).toBe(1);
    } finally {
      globalThis.IntersectionObserver = realIntersectionObserver;
    }
  });
});
