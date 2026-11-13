import { Dialog } from '@angular/cdk/dialog';
import { OverlayContainer } from '@angular/cdk/overlay';
import { computed, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Observable, of, throwError } from 'rxjs';
import {
  restoreIntersectionObserver,
  stubIntersectionObserver,
} from '../../../testing/intersection-observer-testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { AddFeedDialogComponent } from '../../reader/feeds/add-feed/add-feed-dialog.component';
import { LayoutService } from '../../reader/layout.service';
import { SubscriptionDto } from '../../reader/models';
import { ManageActions } from '../../reader/feeds/manage/manage-actions.service';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';
import { FeedDirectoryApi } from './feed-directory-api';
import {
  FeedDirectoryAccountState,
  FeedDirectoryScopeService,
} from './feed-directory-scope.service';
import { FeedDirectorySectionComponent } from './feed-directory-section.component';
import { FeedDirectoryStore } from './feed-directory.store';
import { FeedDirectoryScope } from '../../core/preferences/feed-directory-scope';
import { FeedDirectoryCountryFile, FeedDirectoryIndex } from './feed-directory.models';
import { FeedDirectoryScopeDialogComponent } from './scope-dialog/feed-directory-scope-dialog.component';

const DE: FeedDirectoryCountryFile = {
  country: 'DE',
  categories: ['Politics & Society', 'Sports'],
  languages: ['de', 'en'],
  domains: [
    [
      'zeit.de',
      998,
      1770,
      [0],
      [
        [0, 0, ['https://www.zeit.de/politik/index']],
        [1, 1, ['https://zeit.de/podcast']],
      ],
      'Weekly newspaper.',
    ],
    ['heise.de', 776, 1334, [0, 1], [[0, 0, ['https://www.heise.de/rss/heise.rdf']]], 'IT news.'],
    ['example.de', null, 300, [1], [[1, 0, ['https://example.de/feed']]], 'English only.'],
  ],
};
const AT: FeedDirectoryCountryFile = {
  country: 'AT',
  categories: ['News'],
  languages: ['de'],
  domains: [['orf.at', 500, 3000, [0], [[0, 0, ['https://orf.at/feed']]], 'Public broadcaster.']],
};
const INDEX: FeedDirectoryIndex = {
  countries: [
    { code: 'DE', name: 'Germany', sites: 3, languages: { de: 2, en: 2 } },
    { code: 'AT', name: 'Austria', sites: 1, languages: { de: 1 } },
  ],
  languages: { de: 3, en: 2 },
};
const manySites = (count: number): FeedDirectoryCountryFile => ({
  country: 'DE',
  categories: ['News'],
  languages: ['de'],
  domains: Array.from({ length: count }, (_unused, index) => [
    `site${index}.de`,
    index + 1,
    1000,
    [0],
    [[0, 0, [`https://site${index}.de/feed`]]],
    null,
  ]),
});
const NO_SCOPE: FeedDirectoryScope = { countries: [], languages: [] };

describe('FeedDirectorySectionComponent', () => {
  const scope = signal<FeedDirectoryScope>(NO_SCOPE);
  const isPhone = signal(false);
  const accountState = signal<FeedDirectoryAccountState>('ready');
  let retryAccount: jest.Mock;
  let country: jest.Mock<Observable<FeedDirectoryCountryFile>, [string]>;
  let dialogOpen: jest.Mock;
  const subscriptions = signal<Partial<SubscriptionDto>[]>([]);
  let reloadQuietly: jest.Mock;
  const subscribeOnReload = (feedUrl: string): void => {
    reloadQuietly.mockImplementation(() => subscriptions.update((held) => [...held, { feedUrl }]));
  };
  let fixture: ComponentFixture<FeedDirectorySectionComponent>;

  async function render(initial: FeedDirectoryScope): Promise<HTMLElement> {
    scope.set(initial);
    isPhone.set(false);
    accountState.set('ready');
    retryAccount = jest.fn();
    country = jest.fn((code: string) => of(code === 'DE' ? DE : AT));
    dialogOpen = jest.fn();
    reloadQuietly = jest.fn();
    subscriptions.set([{ id: 1, feedUrl: 'https://www.heise.de/other.xml' }]);
    await TestBed.configureTestingModule({
      imports: [FeedDirectorySectionComponent, provideTranslocoTesting()],
      providers: [
        {
          provide: FeedDirectoryApi,
          useValue: {
            index: () => of(INDEX),
            country,
          },
        },
        {
          provide: FeedDirectoryScopeService,
          useValue: {
            scope,
            accountState,
            retryAccount,
            hasScope: computed(() => scope().countries.length > 0),
            save: (saved: FeedDirectoryScope) => {
              scope.set(saved);
              return of(true);
            },
          },
        },
        { provide: Dialog, useValue: { open: dialogOpen } },
        { provide: ManageActions, useValue: { editSubscription: jest.fn() } },
        { provide: LayoutService, useValue: { isPhone, isCoarse: signal(false) } },
        {
          provide: SubscriptionsStore,
          useValue: {
            reloadQuietly,
            subscriptions,
            resolved: signal(true),
            error: signal(null),
            loadIfStale: jest.fn(),
          },
        },
      ],
    }).compileComponents();
    fixture = TestBed.createComponent(FeedDirectorySectionComponent);
    await settle();
    return fixture.nativeElement;
  }

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  const element = (): HTMLElement => fixture.nativeElement;
  const store = (): FeedDirectoryStore => fixture.debugElement.injector.get(FeedDirectoryStore);
  const text = (node: Element | null): string =>
    (node?.textContent ?? '').replace(/\s+/g, ' ').trim();
  const hosts = (): string[] =>
    Array.from(element().querySelectorAll('app-feed-directory-row .name')).map(
      (name) => name.textContent!,
    );

  afterEach(() => TestBed.inject(OverlayContainer).ngOnDestroy());

  it('shows the scope picker inline and requests no country while no scope is saved', async () => {
    const page = await render(NO_SCOPE);

    expect(page.querySelector('app-feed-directory-scope-picker')).not.toBeNull();
    expect(text(page.querySelector('.g-title'))).toBe('Choose your feed directory');
    expect(page.querySelector('.rows-card')).toBeNull();
    expect(country).not.toHaveBeenCalled();
  });

  it('waits for the account before it offers the picker', async () => {
    const page = await render(NO_SCOPE);
    accountState.set('loading');
    await settle();

    expect(page.querySelector('app-feed-directory-scope-picker')).toBeNull();
    expect(page.querySelector('app-skeleton')).not.toBeNull();

    accountState.set('ready');
    await settle();

    expect(page.querySelector('app-feed-directory-scope-picker')).not.toBeNull();
  });

  it('offers a retry instead of the picker when the account failed to load', async () => {
    const page = await render(NO_SCOPE);
    accountState.set('failed');
    await settle();

    expect(page.querySelector('app-feed-directory-scope-picker')).toBeNull();
    const banner = page.querySelector('app-error-banner')!;
    expect(text(banner)).toContain("The directory couldn't be loaded.");

    banner.querySelector<HTMLButtonElement>('button.action')!.click();

    expect(retryAccount).toHaveBeenCalled();
  });

  it('loads the directory once the inline picker saves a scope', async () => {
    const page = await render(NO_SCOPE);
    const checkbox = (list: string, name: string): HTMLInputElement =>
      Array.from(page.querySelectorAll<HTMLElement>(`[data-test="${list}"] .opt`))
        .find((option) => option.textContent!.includes(name))!
        .querySelector('input')!;

    checkbox('countries', 'Austria').click();
    checkbox('languages', 'German').click();
    fixture.detectChanges();
    page.querySelector<HTMLElement>('[data-test="save"]')!.click();
    await settle();

    expect(country).toHaveBeenCalledWith('AT');
    expect(hosts()).toEqual(['orf.at']);
  });

  it('heads the loaded directory with its total, the rows and the scope chips', async () => {
    const page = await render({ countries: ['DE', 'AT'], languages: ['de'] });

    expect(text(page.querySelector('.g-caption'))).toBe('3 sites in your countries and languages');
    expect(hosts()).toEqual(['orf.at', 'heise.de', 'zeit.de']);
    expect(Array.from(page.querySelectorAll('[data-test="scope"] .scope-chip')).map(text)).toEqual([
      'Germany',
      'Austria',
      'German',
    ]);
    expect(text(page.querySelector('.shown'))).toBe('3 shown');
    expect(
      Array.from(page.querySelectorAll('app-feed-directory-row')).map(
        (row) => row.querySelector('.follow') !== null,
      ),
    ).toEqual([false, true, false]);
  });

  it('offers the Countries and Language pills only for a scope holding more than one', async () => {
    const single = await render({ countries: ['DE'], languages: ['de'] });

    expect(single.querySelector('[data-test="countries"]')).toBeNull();
    expect(single.querySelector('[data-test="language"]')).toBeNull();

    TestBed.resetTestingModule();
    const double = await render({ countries: ['DE', 'AT'], languages: ['de', 'en'] });

    expect(double.querySelector('[data-test="countries"]')).not.toBeNull();
    expect(double.querySelector('[data-test="language"]')).not.toBeNull();
  });

  it('narrows to one country through the Countries pill', async () => {
    const page = await render({ countries: ['DE', 'AT'], languages: ['de'] });

    page.querySelector<HTMLElement>('[data-test="countries"] .pill')!.click();
    fixture.detectChanges();
    const options = Array.from(
      TestBed.inject(OverlayContainer)
        .getContainerElement()
        .querySelectorAll<HTMLElement>('button.option'),
    );
    expect(options.map((option) => text(option.querySelector('.name')))).toEqual([
      'All countries',
      'Germany',
      'Austria',
    ]);
    expect(options.map((option) => text(option.querySelector('.count')))).toEqual(['3', '2', '1']);

    options[2].click();
    await settle();

    expect(hosts()).toEqual(['orf.at']);
    expect(text(page.querySelector('[data-test="countries"] .pill'))).toContain('Austria');
  });

  it('narrows the rows to what the search matches once typing pauses', async () => {
    const page = await render({ countries: ['DE', 'AT'], languages: ['de'] });
    const search = page.querySelector<HTMLInputElement>('[data-test="search"]')!;
    jest.useFakeTimers();

    search.value = 'broadcaster';
    search.dispatchEvent(new Event('input'));
    jest.advanceTimersByTime(149);
    expect(store().query()).toBe('');
    jest.advanceTimersByTime(1);
    jest.useRealTimers();
    await settle();

    expect(hosts()).toEqual(['orf.at']);
    expect(text(page.querySelector('.shown'))).toBe('1 shown');
  });

  it('says so when nothing matches', async () => {
    const page = await render({ countries: ['DE'], languages: ['de'] });

    store().query.set('nothing like this');
    await settle();

    expect(text(page.querySelector('.empty'))).toBe('No sites match these filters.');
    expect(page.querySelector('app-feed-directory-row')).toBeNull();
  });

  it('switches between channels and podcasts', async () => {
    const page = await render({ countries: ['DE', 'AT'], languages: ['de'] });
    const podcasts = Array.from(
      page.querySelectorAll<HTMLButtonElement>('app-segmented-choice button'),
    ).find((button) => text(button) === 'Podcasts')!;

    podcasts.click();
    await settle();

    expect(store().mode()).toBe('podcasts');
    expect(hosts()).toEqual(['zeit.de']);
  });

  it('shows the load failure with a Retry that loads again', async () => {
    const page = await render({ countries: ['DE'], languages: ['de'] });
    country.mockReturnValue(throwError(() => new Error('offline')));
    store().load();
    await settle();

    const banner = page.querySelector('.rows-card app-error-banner')!;
    expect(text(banner)).toContain("The directory couldn't be loaded.");
    const load = jest.spyOn(store(), 'load');
    country.mockReturnValue(of(DE));
    banner.querySelector<HTMLButtonElement>('button.action')!.click();
    await settle();

    expect(load).toHaveBeenCalled();
    expect(hosts()).toEqual(['heise.de', 'zeit.de']);
  });

  it('opens the scope dialog from Change scope', async () => {
    const page = await render({ countries: ['DE'], languages: ['de'] });

    page.querySelector<HTMLElement>('[data-test="change-scope"]')!.click();

    expect(dialogOpen).toHaveBeenCalledWith(FeedDirectoryScopeDialogComponent, {
      panelClass: 'app-dialog',
    });
  });

  it('subscribes from a row through the add-feed dialog and marks the feed', async () => {
    const page = await render({ countries: ['DE'], languages: ['de'] });
    dialogOpen.mockReturnValue({ closed: of({ id: 3 }) });
    subscribeOnReload('https://www.zeit.de/politik/index');
    const zeit = (): Element =>
      Array.from(page.querySelectorAll('app-feed-directory-row')).find(
        (row) => text(row.querySelector('.name')) === 'zeit.de',
      )!;

    zeit().querySelector<HTMLButtonElement>('[data-test="subscribe"] button')!.click();
    await settle();

    expect(dialogOpen).toHaveBeenCalledWith(AddFeedDialogComponent, {
      panelClass: 'app-dialog',
      data: { url: 'https://www.zeit.de/politik/index' },
    });
    expect(reloadQuietly).toHaveBeenCalled();
    expect(text(zeit().querySelector('[data-test="edit"]'))).toContain('Subscribed');
  });

  it('subscribes to one of several feeds through the popover and counts it on the row', async () => {
    const page = await render({ countries: ['DE'], languages: ['de'] });
    const spiegel: FeedDirectoryCountryFile = {
      country: 'DE',
      categories: ['World'],
      languages: ['de'],
      domains: [
        [
          'spiegel.de',
          529,
          436,
          [0],
          [
            [
              0,
              0,
              ['https://www.spiegel.de/index.rss', 'https://www.spiegel.de/ausland/index.rss'],
            ],
          ],
          null,
        ],
      ],
    };
    country.mockReturnValue(of(spiegel));
    subscribeOnReload('https://www.spiegel.de/ausland/index.rss');
    store().load();
    await settle();
    dialogOpen.mockReturnValue({ closed: of({ id: 4 }) });

    page.querySelector<HTMLButtonElement>('[data-test="feeds"] button')!.click();
    await settle();
    const overlay = TestBed.inject(OverlayContainer).getContainerElement();
    overlay.querySelectorAll<HTMLButtonElement>('.feed app-button button')[1].click();
    await settle();

    expect(dialogOpen).toHaveBeenCalledWith(AddFeedDialogComponent, {
      panelClass: 'app-dialog',
      data: { url: 'https://www.spiegel.de/ausland/index.rss' },
    });
    expect(text(page.querySelector('[data-test="feeds"] .subscribed-part'))).toBe('· 1 subscribed');
  });

  it('reloads when a saved scope changes what the directory holds', async () => {
    await render({ countries: ['DE'], languages: ['de'] });
    country.mockClear();

    scope.set({ countries: ['AT'], languages: ['de'] });
    await settle();

    expect(country).toHaveBeenCalledWith('AT');
    expect(hosts()).toEqual(['orf.at']);
  });

  it('keeps the directory as it is when the account arrives again with the same scope', async () => {
    await render({ countries: ['DE'], languages: ['de'] });
    country.mockClear();

    scope.set({ countries: ['DE'], languages: ['de'] });
    await settle();

    expect(country).not.toHaveBeenCalled();
  });

  it('loads once the account arrives with a scope after the page opened', async () => {
    await render(NO_SCOPE);

    scope.set({ countries: ['DE'], languages: ['de'] });
    await settle();

    expect(country).toHaveBeenCalledWith('DE');
    expect(hosts()).toEqual(['heise.de', 'zeit.de']);
  });

  describe('batches', () => {
    let watcher: ReturnType<typeof stubIntersectionObserver>;
    const sentinel = (): HTMLElement | null => element().querySelector('[data-test="more"]');

    async function renderSites(count: number): Promise<void> {
      watcher = stubIntersectionObserver();
      await render(NO_SCOPE);
      country.mockReturnValue(of(manySites(count)));
      scope.set({ countries: ['DE'], languages: ['de'] });
      await settle();
    }

    afterEach(restoreIntersectionObserver);

    it('adds the next batch when the end of the list comes into view', async () => {
      await renderSites(150);
      expect(hosts().length).toBe(100);
      expect(watcher.observed).toContain(sentinel());

      watcher.report(sentinel()!, true);
      await settle();

      expect(hosts().length).toBe(150);
      expect(sentinel()).toBeNull();
    });

    it('watches the end of the list again after each batch, so a sentinel left in view keeps loading', async () => {
      await renderSites(250);
      const watched = sentinel()!;

      watcher.report(watched, true);
      await settle();

      expect(watcher.observed.filter((target) => target === sentinel()).length).toBe(2);
    });

    it('stops watching when the page goes away', async () => {
      await renderSites(150);

      fixture.destroy();

      expect(watcher.disconnect).toHaveBeenCalled();
    });
  });

  it('carries the attribution of the directory data', async () => {
    const page = await render({ countries: ['DE'], languages: ['de'] });

    expect(text(page.querySelector('.attribution'))).toContain('InfoBubble sources catalog (ODbL)');
  });
});
