import { Dialog } from '@angular/cdk/dialog';
import { CdkFixedSizeVirtualScroll } from '@angular/cdk/scrolling';
import { By } from '@angular/platform-browser';
import { OverlayContainer } from '@angular/cdk/overlay';
import { computed, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Observable, of, throwError } from 'rxjs';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { LayoutService } from '../../reader/layout.service';
import { FeedDirectoryApi } from './feed-directory-api';
import { FeedDirectoryScopeService } from './feed-directory-scope.service';
import { FeedDirectorySectionComponent } from './feed-directory-section.component';
import { FeedDirectoryStore } from './feed-directory.store';
import {
  FeedDirectoryCountryFile,
  FeedDirectoryIndex,
  FeedDirectoryScope,
} from './feed-directory.models';
import { FEED_DIRECTORY_ROW_HEIGHT } from './row/feed-directory-row.component';
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
const NO_SCOPE: FeedDirectoryScope = { countries: [], languages: [] };

describe('FeedDirectorySectionComponent', () => {
  const scope = signal<FeedDirectoryScope>(NO_SCOPE);
  const isPhone = signal(false);
  const known = signal(true);
  const accountFailed = signal(false);
  let retryAccount: jest.Mock;
  let country: jest.Mock<Observable<FeedDirectoryCountryFile>, [string]>;
  let dialogOpen: jest.Mock;
  let fixture: ComponentFixture<FeedDirectorySectionComponent>;

  async function render(initial: FeedDirectoryScope): Promise<HTMLElement> {
    scope.set(initial);
    isPhone.set(false);
    known.set(true);
    accountFailed.set(false);
    retryAccount = jest.fn();
    country = jest.fn((code: string) => of(code === 'DE' ? DE : AT));
    dialogOpen = jest.fn();
    await TestBed.configureTestingModule({
      imports: [FeedDirectorySectionComponent, provideTranslocoTesting()],
      providers: [
        {
          provide: FeedDirectoryApi,
          useValue: {
            index: () => of(INDEX),
            subscribed: () => of({ urls: [], hosts: ['heise.de'] }),
            country,
          },
        },
        {
          provide: FeedDirectoryScopeService,
          useValue: {
            scope,
            known,
            accountFailed,
            retryAccount,
            hasScope: computed(() => scope().countries.length > 0),
            save: (saved: FeedDirectoryScope) => {
              scope.set(saved);
              return of(true);
            },
          },
        },
        { provide: Dialog, useValue: { open: dialogOpen } },
        { provide: LayoutService, useValue: { isPhone } },
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
    known.set(false);
    await settle();

    expect(page.querySelector('app-feed-directory-scope-picker')).toBeNull();
    expect(page.querySelector('app-skeleton')).not.toBeNull();

    known.set(true);
    await settle();

    expect(page.querySelector('app-feed-directory-scope-picker')).not.toBeNull();
  });

  it('offers a retry instead of the picker when the account failed to load', async () => {
    const page = await render(NO_SCOPE);
    accountFailed.set(true);
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

  it('narrows the rows to what the search matches', async () => {
    const page = await render({ countries: ['DE', 'AT'], languages: ['de'] });
    const search = page.querySelector<HTMLInputElement>('[data-test="search"]')!;

    search.value = 'broadcaster';
    search.dispatchEvent(new Event('input'));
    await settle();

    expect(hosts()).toEqual(['orf.at']);
    expect(text(page.querySelector('.shown'))).toBe('1 shown');
  });

  it('says so when nothing matches', async () => {
    const page = await render({ countries: ['DE'], languages: ['de'] });

    store().query.set('nothing like this');
    await settle();

    expect(text(page.querySelector('.empty'))).toBe('No sites match these filters.');
    expect(page.querySelector('cdk-virtual-scroll-viewport')).toBeNull();
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
    const retry = jest.spyOn(store(), 'retry');
    country.mockReturnValue(of(DE));
    banner.querySelector<HTMLButtonElement>('button.action')!.click();
    await settle();

    expect(retry).toHaveBeenCalled();
    expect(hosts()).toEqual(['heise.de', 'zeit.de']);
  });

  it('opens the scope dialog from Change scope', async () => {
    const page = await render({ countries: ['DE'], languages: ['de'] });

    page.querySelector<HTMLElement>('[data-test="change-scope"]')!.click();

    expect(dialogOpen).toHaveBeenCalledWith(FeedDirectoryScopeDialogComponent, {
      panelClass: 'app-dialog',
    });
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

  it('scrolls by one row height, and sizes the rows by it, for each breakpoint', async () => {
    await render({ countries: ['DE'], languages: ['de'] });
    const viewport = (): HTMLElement => element().querySelector('cdk-virtual-scroll-viewport')!;
    const itemSize = (): number =>
      fixture.debugElement
        .query(By.directive(CdkFixedSizeVirtualScroll))
        .injector.get(CdkFixedSizeVirtualScroll).itemSize;

    expect(itemSize()).toBe(FEED_DIRECTORY_ROW_HEIGHT.wide);
    expect(viewport().style.getPropertyValue('--row-h')).toBe(
      `${FEED_DIRECTORY_ROW_HEIGHT.wide}px`,
    );

    isPhone.set(true);
    await settle();

    expect(itemSize()).toBe(FEED_DIRECTORY_ROW_HEIGHT.phone);
    expect(viewport().style.getPropertyValue('--row-h')).toBe(
      `${FEED_DIRECTORY_ROW_HEIGHT.phone}px`,
    );
  });

  it('carries the attribution of the directory data', async () => {
    const page = await render({ countries: ['DE'], languages: ['de'] });

    expect(text(page.querySelector('.attribution'))).toContain('InfoBubble sources catalog (ODbL)');
  });
});
