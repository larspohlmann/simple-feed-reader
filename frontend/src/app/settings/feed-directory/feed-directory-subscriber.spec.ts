import { Dialog } from '@angular/cdk/dialog';
import { OverlayContainer } from '@angular/cdk/overlay';
import { ScrollDispatcher } from '@angular/cdk/scrolling';
import { ApplicationRef, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Observable, Subject, firstValueFrom, of } from 'rxjs';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { AddFeedDialogComponent } from '../../reader/feeds/add-feed/add-feed-dialog.component';
import { LayoutService } from '../../reader/layout.service';
import { SubscriptionDto } from '../../reader/models';
import { ManageActions } from '../../reader/feeds/manage/manage-actions.service';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';
import { ActionSheet } from '../../shared/action-sheet/action-sheet.service';
import { ActionSheetData } from '../../shared/action-sheet/action-sheet.component';
import {
  FeedDirectoryFeed,
  FeedDirectoryMode,
  FeedDirectoryRow,
  modeSummaries,
} from './feed-directory-rows';
import { FeedDirectoryStore } from './feed-directory.store';
import { FeedDirectorySubscriber } from './feed-directory-subscriber';

const SUBSCRIBED_URL = 'https://www.spiegel.de/kultur/index.rss';

const channel = (url: string, category: string, subscribed = false): FeedDirectoryFeed => ({
  url,
  category,
  podcast: false,
  subscribed,
});

const row = (feeds: FeedDirectoryFeed[]): FeedDirectoryRow => ({
  host: 'spiegel.de',
  country: 'DE',
  rank: 529,
  length: null,
  languages: ['de'],
  summary: null,
  feeds,
  byMode: modeSummaries(feeds),
  following: false,
  searchText: 'spiegel.de',
});

const ONE = row([
  channel('https://www.spiegel.de/index.rss', 'World'),
  { ...channel('https://www.spiegel.de/podcast', 'World'), podcast: true },
]);
const SEVERAL = row([
  channel('https://www.spiegel.de/kultur/index.rss', 'Culture', true),
  channel('https://www.spiegel.de/ausland/index.rss', 'World'),
  channel('https://www.spiegel.de/kultur/kino/index.rss', 'Culture'),
]);

describe('FeedDirectorySubscriber', () => {
  const isCoarse = signal(false);
  const mode = signal<FeedDirectoryMode>('channels');
  const categories = signal<ReadonlySet<string>>(new Set());
  let closed: Subject<SubscriptionDto | undefined>;
  let dialogOpen: jest.Mock;
  let sheetOpen: jest.Mock<Observable<string | undefined>, [ActionSheetData]>;
  let reloadQuietly: jest.Mock;
  let editSubscription: jest.Mock;
  let subscriber: FeedDirectorySubscriber;
  let anchor: HTMLElement;

  beforeEach(() => {
    isCoarse.set(false);
    mode.set('channels');
    categories.set(new Set());
    closed = new Subject();
    dialogOpen = jest.fn(() => ({ closed }));
    sheetOpen = jest.fn<Observable<string | undefined>, [ActionSheetData]>(() => of(undefined));
    reloadQuietly = jest.fn();
    editSubscription = jest.fn();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        FeedDirectorySubscriber,
        { provide: Dialog, useValue: { open: dialogOpen } },
        { provide: ActionSheet, useValue: { open: sheetOpen } },
        { provide: LayoutService, useValue: { isCoarse } },
        {
          provide: FeedDirectoryStore,
          useValue: {
            mode,
            categories,
            subscriptionFor: (url: string) => (url === SUBSCRIBED_URL ? { id: 7 } : null),
          },
        },
        { provide: ManageActions, useValue: { editSubscription } },
        { provide: SubscriptionsStore, useValue: { reloadQuietly } },
      ],
    });
    subscriber = TestBed.inject(FeedDirectorySubscriber);
    anchor = document.createElement('app-button');
    anchor.appendChild(document.createElement('button'));
    document.body.appendChild(anchor);
  });

  afterEach(() => {
    anchor.remove();
    TestBed.inject(OverlayContainer).ngOnDestroy();
  });

  const container = (): HTMLElement => TestBed.inject(OverlayContainer).getContainerElement();
  const popover = (): HTMLElement | null =>
    container().querySelector('app-feed-directory-feeds-popover');
  const render = (): void => TestBed.inject(ApplicationRef).tick();

  function openPopover(): void {
    subscriber.open({ row: SEVERAL, anchor });
    render();
  }

  it('opens the add-feed dialog for the one feed of the mode', () => {
    subscriber.open({ row: ONE, anchor });

    expect(dialogOpen).toHaveBeenCalledWith(AddFeedDialogComponent, {
      panelClass: 'app-dialog',
      data: { url: 'https://www.spiegel.de/index.rss' },
    });
    expect(popover()).toBeNull();
    expect(sheetOpen).not.toHaveBeenCalled();
  });

  it('opens the add-feed dialog directly when the chosen category leaves one feed', () => {
    categories.set(new Set(['World']));

    subscriber.open({ row: SEVERAL, anchor });

    expect(dialogOpen).toHaveBeenCalledWith(AddFeedDialogComponent, {
      panelClass: 'app-dialog',
      data: { url: 'https://www.spiegel.de/ausland/index.rss' },
    });
    expect(popover()).toBeNull();
    expect(sheetOpen).not.toHaveBeenCalled();
  });

  it('reloads the subscriptions once the dialog subscribes', () => {
    subscriber.open({ row: ONE, anchor });
    closed.next({ id: 7 } as SubscriptionDto);

    expect(reloadQuietly).toHaveBeenCalledTimes(1);
  });

  it('changes nothing when the dialog is dismissed', () => {
    subscriber.open({ row: ONE, anchor });
    closed.next(undefined);

    expect(reloadQuietly).not.toHaveBeenCalled();
  });

  it('opens the edit dialog for a chosen feed you subscribe to', () => {
    subscriber.choose(SUBSCRIBED_URL);

    expect(editSubscription).toHaveBeenCalledWith(expect.objectContaining({ id: 7 }));
    expect(dialogOpen).not.toHaveBeenCalled();
  });

  it('offers to subscribe to a chosen feed you do not subscribe to', () => {
    subscriber.choose('https://nowhere.example/feed');

    expect(editSubscription).not.toHaveBeenCalled();
    expect(dialogOpen).toHaveBeenCalledWith(
      AddFeedDialogComponent,
      expect.objectContaining({ data: { url: 'https://nowhere.example/feed' } }),
    );
  });

  describe('several feeds on a fine pointer', () => {
    it('opens the feeds popover with the row and its feeds in the mode', () => {
      openPopover();

      expect(popover()).not.toBeNull();
      expect(popover()!.querySelectorAll('.feed')).toHaveLength(3);
      expect(sheetOpen).not.toHaveBeenCalled();
      expect(dialogOpen).not.toHaveBeenCalled();
    });

    it('lists only the feeds of the chosen categories', () => {
      categories.set(new Set(['Culture']));

      openPopover();

      expect(
        Array.from(popover()!.querySelectorAll('.path')).map((path) => path.textContent),
      ).toEqual(['/kultur/index.rss', '/kultur/kino/index.rss']);
    });

    it('moves focus to the first feed it offers', () => {
      openPopover();

      expect(document.activeElement).toBe(popover()!.querySelector('.feed button'));
    });

    it('closes on a choice and opens the dialog for that feed', () => {
      openPopover();
      popover()!.querySelector<HTMLButtonElement>('.feed [data-test="subscribe"] button')!.click();
      render();

      expect(popover()).toBeNull();
      expect(dialogOpen).toHaveBeenCalledWith(AddFeedDialogComponent, {
        panelClass: 'app-dialog',
        data: { url: 'https://www.spiegel.de/kultur/kino/index.rss' },
      });
    });

    it('closes the popover and edits a subscribed feed chosen there', () => {
      openPopover();
      popover()!.querySelector<HTMLButtonElement>('[data-test="edit"] button')!.click();
      render();

      expect(popover()).toBeNull();
      expect(editSubscription).toHaveBeenCalledWith(expect.objectContaining({ id: 7 }));
      expect(dialogOpen).not.toHaveBeenCalled();
    });

    it('closes on Escape and gives focus back to the button that opened it', () => {
      openPopover();
      popover()!.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      render();

      expect(popover()).toBeNull();
      expect(document.activeElement).toBe(anchor.querySelector('button'));
      expect(dialogOpen).not.toHaveBeenCalled();
    });

    it('leaves focus where it is when a page scroll closes it', async () => {
      openPopover();
      const scrolled = firstValueFrom(TestBed.inject(ScrollDispatcher).scrolled());
      document.dispatchEvent(new Event('scroll'));
      await scrolled;
      render();

      expect(popover()).toBeNull();
      expect(document.activeElement).not.toBe(anchor.querySelector('button'));
    });

    it('closes on a click outside', () => {
      openPopover();
      container().querySelector<HTMLElement>('.cdk-overlay-backdrop')!.click();
      render();

      expect(popover()).toBeNull();
    });

    it('closes from its close button', () => {
      openPopover();
      popover()!.querySelector<HTMLButtonElement>('button.close')!.click();
      render();

      expect(popover()).toBeNull();
    });

    it('keeps one popover open at a time', () => {
      openPopover();
      openPopover();

      expect(container().querySelectorAll('app-feed-directory-feeds-popover')).toHaveLength(1);
    });
  });

  describe('several feeds on a coarse pointer', () => {
    beforeEach(() => isCoarse.set(true));

    it('opens an action sheet with a note per category and a check on the subscribed feeds', () => {
      subscriber.open({ row: SEVERAL, anchor });

      expect(popover()).toBeNull();
      expect(sheetOpen).toHaveBeenCalledWith({
        title: 'spiegel.de · 3 feeds',
        actions: [
          { note: 'Culture · 2' },
          {
            id: 'https://www.spiegel.de/kultur/index.rss',
            label: '/kultur/index.rss',
            symbol: 'check',
          },
          {
            id: 'https://www.spiegel.de/kultur/kino/index.rss',
            label: '/kultur/kino/index.rss',
            symbol: 'add',
          },
          { note: 'World · 1' },
          {
            id: 'https://www.spiegel.de/ausland/index.rss',
            label: '/ausland/index.rss',
            symbol: 'add',
          },
        ],
      });
    });

    it('offers only the feeds of the chosen categories on the sheet', () => {
      categories.set(new Set(['Culture']));

      subscriber.open({ row: SEVERAL, anchor });

      expect(sheetOpen).toHaveBeenCalledWith({
        title: 'spiegel.de · 2 feeds',
        actions: [
          { note: 'Culture · 2' },
          {
            id: 'https://www.spiegel.de/kultur/index.rss',
            label: '/kultur/index.rss',
            symbol: 'check',
          },
          {
            id: 'https://www.spiegel.de/kultur/kino/index.rss',
            label: '/kultur/kino/index.rss',
            symbol: 'add',
          },
        ],
      });
    });

    it('opens the dialog for the feed chosen on the sheet', () => {
      sheetOpen.mockReturnValue(of('https://www.spiegel.de/ausland/index.rss'));

      subscriber.open({ row: SEVERAL, anchor });

      expect(dialogOpen).toHaveBeenCalledWith(AddFeedDialogComponent, {
        panelClass: 'app-dialog',
        data: { url: 'https://www.spiegel.de/ausland/index.rss' },
      });
    });

    it('edits a subscribed feed chosen on the sheet instead of opening the add-feed dialog', () => {
      sheetOpen.mockReturnValue(of(SUBSCRIBED_URL));

      subscriber.open({ row: SEVERAL, anchor });

      expect(editSubscription).toHaveBeenCalledWith(expect.objectContaining({ id: 7 }));
      expect(dialogOpen).not.toHaveBeenCalled();
    });

    it('opens nothing when the sheet is dismissed', () => {
      subscriber.open({ row: SEVERAL, anchor });

      expect(dialogOpen).not.toHaveBeenCalled();
    });
  });
});
