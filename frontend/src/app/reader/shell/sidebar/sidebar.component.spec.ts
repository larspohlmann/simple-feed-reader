import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { API_BASE_URL } from '../../../core/api';
import { AuthService, CurrentUser } from '../../../core/auth/auth.service';
import { AiAvailabilityService } from '../../../core/ai-availability.service';
import { RefreshService } from '../../state/refresh.service';
import { RecommendationsService } from '../../state/recommendations.service';
import { CdkDrag, CdkDragDrop } from '@angular/cdk/drag-drop';
import { DropData, SidebarComponent } from './sidebar.component';
import { TagNode } from '../../state/subscriptions.store';
import { Selection } from '../../query/query';
import { SavedSearchDto, SubscriptionDto, TagDto } from '../../models';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { LayoutService } from '../../layout.service';
import { SidebarVisibilityService } from '../sidebar-visibility.service';
import { ActionSheet } from '../../../shared/action-sheet/action-sheet.service';
import { of } from 'rxjs';
import { By } from '@angular/platform-browser';
import { ManageActions } from '../../feeds/manage/manage-actions.service';

const manageActions = {
  editTag: jest.fn(),
  deleteTag: jest.fn(),
  editSubscription: jest.fn(),
  unsubscribe: jest.fn(),
  setIncludeInAllItems: jest.fn(),
  setIncludeInForYou: jest.fn(),
  moveFeedToTag: jest.fn(),
  reorderTags: jest.fn(),
  reorderUntagged: jest.fn(),
  reorderTagFeeds: jest.fn(),
};

beforeEach(() => Object.values(manageActions).forEach((spy) => spy.mockReset()));

const account = (trialEndsAt: string | null): CurrentUser => ({
  id: 1,
  email: 'me@x',
  roles: ['ROLE_USER'],
  status: 'active',
  createdAt: '2026-01-01T00:00:00Z',
  locale: 'en',
  trialEndsAt,
  preferences: {
    scrapeFallbackEnabled: false,
    digest: {
      enabled: false,
      cadence: 'daily',
      sendHour: 8,
      weekday: 1,
      format: 'html',
      timezone: 'UTC',
    },
    passkeyOfferAnswered: true,
    magazineStyle: 'boxed',
  },
  ai: { ready: false, model: null },
  mail: { enabled: true },
  emailVerified: true,
});

const inDays = (days: number): string => new Date(Date.now() + days * 86_400_000).toISOString();

const sub = (id: number, unread = 0): SubscriptionDto => ({
  id,
  feedId: id * 10,
  title: `s${id}`,
  faviconUrl: null,
  customTitle: null,
  feedUrl: `https://f/${id}`,
  siteUrl: null,
  description: null,
  imageUrl: null,
  status: 'active',
  sourceFormat: 'xml',
  createdAt: 'x',
  lastFetchedAt: null,
  lastSuccessfulFetchAt: null,
  lastNewContentAt: null,
  nextFetchAt: null,
  consecutiveFailures: 0,
  lastErrorMessage: null,
  position: 0,
  tags: [],
  unreadCount: unread,
  entryCount: 0,
  includeInAllItems: true,
  includeInForYou: true,
});

function mount(
  over: Partial<{
    tagTree: TagNode[];
    untagged: SubscriptionDto[];
    totalUnread: number;
    favoritesCount: number;
    keptCount: number;
    selection: Selection;
    user: CurrentUser | null;
    coarse: boolean;
    narrow: boolean;
    organising: boolean;
    sheetChoice?: string;
    searchLoading: boolean;
    savedSearches: SavedSearchDto[];
    activeSavedSearchId: number | null;
    mailEnabled: boolean;
    digestEnabled: boolean;
  }> = {},
) {
  TestBed.configureTestingModule({
    imports: [SidebarComponent, provideTranslocoTesting()],
    providers: [
      { provide: ManageActions, useValue: manageActions },
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      { provide: API_BASE_URL, useValue: 'https://api.test' },
      { provide: AuthService, useValue: { user: signal(over.user ?? account(null)) } },
      {
        provide: LayoutService,
        useValue: {
          isCoarse: signal(over.coarse ?? false),
          isNarrow: signal(over.narrow ?? false),
        },
      },
      { provide: ActionSheet, useValue: { open: jest.fn(() => of(over.sheetChoice)) } },
    ],
  });
  const fixture = TestBed.createComponent(SidebarComponent);
  fixture.componentRef.setInput('tagTree', over.tagTree ?? []);
  fixture.componentRef.setInput('untagged', over.untagged ?? []);
  fixture.componentRef.setInput('totalUnread', over.totalUnread ?? 0);
  fixture.componentRef.setInput('favoritesCount', over.favoritesCount ?? 0);
  fixture.componentRef.setInput('keptCount', over.keptCount ?? 0);
  fixture.componentRef.setInput(
    'selection',
    over.selection ?? { kind: 'all', id: null, unread: true },
  );
  fixture.componentRef.setInput('loading', false);
  fixture.componentRef.setInput('searchLoading', over.searchLoading ?? false);
  fixture.componentRef.setInput('organising', over.organising ?? false);
  fixture.componentRef.setInput('savedSearches', over.savedSearches ?? []);
  fixture.componentRef.setInput('activeSavedSearchId', over.activeSavedSearchId ?? null);
  fixture.componentRef.setInput('mailEnabled', over.mailEnabled ?? false);
  fixture.componentRef.setInput('digestEnabled', over.digestEnabled ?? true);
  fixture.detectChanges();
  return fixture;
}

describe('SidebarComponent', () => {
  it('shows the all-items total and marks it active', () => {
    const element = mount({ totalUnread: 24 }).nativeElement as HTMLElement;
    const all = element.querySelector('.nav.all')!;
    expect(all.textContent).toContain('24');
    expect(all.classList).toContain('active');
  });

  it('shows favourite and kept totals on their nav items, omitting a zero', () => {
    const element = mount({ favoritesCount: 5, keptCount: 0 }).nativeElement as HTMLElement;
    const navs = [...element.querySelectorAll('.nav')];
    const fav = navs.find((nav) => nav.textContent?.includes('Favorites'))!;
    const kept = navs.find((nav) => nav.textContent?.includes('Kept'))!;
    expect(fav.querySelector('.count')?.textContent).toContain('5');
    expect(kept.querySelector('.count')).toBeNull();
  });

  it('emits refresh and addFeed from the action buttons', () => {
    const fixture = mount();
    const element = fixture.nativeElement as HTMLElement;
    const refresh = jest.fn();
    const addFeed = jest.fn();
    fixture.componentInstance.refresh.subscribe(refresh);
    fixture.componentInstance.addFeed.subscribe(addFeed);
    (element.querySelector('.act[aria-label="Refresh"]') as HTMLButtonElement).click();
    (element.querySelector('.act[aria-label="Add feed"]') as HTMLButtonElement).click();
    expect(refresh).toHaveBeenCalledTimes(1);
    expect(addFeed).toHaveBeenCalledTimes(1);
  });

  it('disables Refresh while refreshing and shows no progress bar of its own', () => {
    const fixture = mount();
    TestBed.inject(RefreshService).running.set(true);
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    expect(
      (element.querySelector('.act[aria-label="Refresh"]') as HTMLButtonElement).disabled,
    ).toBe(true);
    // The refresh has exactly one bar and it belongs to the app bar. A second one
    // here was narrower than the first, sat directly under it on desktop, and drew
    // the same number twice (#721).
    expect(element.querySelector('.prog')).toBeNull();
  });

  it('renders tags with summed counts and reveals subs when expanded', () => {
    const node: TagNode = {
      tag: { id: 20, name: 'Tech', color: null, icon: null, position: 0 },
      subscriptions: [sub(1, 3), sub(2, 6)],
      unreadCount: 9,
      entryCount: 0,
    };
    const fixture = mount({ tagTree: [node] });
    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('.tag')!.textContent).toContain('Tech');
    expect(element.querySelector('.tag')!.textContent).toContain('9');
    expect(element.querySelectorAll('.tag-sub').length).toBe(0);
    (element.querySelector('.tag .chevzone') as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(element.querySelectorAll('.tag-sub').length).toBe(2);
  });

  it('renders the tag icon (tinted with its colour) when set, else the colour dot', () => {
    const withIcon: TagNode = {
      tag: { id: 20, name: 'World', color: '#c08a3e', icon: 'public', position: 0 },
      subscriptions: [],
      unreadCount: 0,
      entryCount: 0,
    };
    const withoutIcon: TagNode = {
      tag: { id: 21, name: 'Plain', color: null, icon: null, position: 1 },
      subscriptions: [],
      unreadCount: 0,
      entryCount: 0,
    };
    const fixture = mount({ tagTree: [withIcon, withoutIcon] });
    const leads = (fixture.nativeElement as HTMLElement).querySelectorAll('.tag .lead');

    const icon = leads[0].querySelector('.material-symbols-outlined') as HTMLElement;
    expect(icon.textContent).toBe('public');
    expect(leads[0].querySelector('.dot')).toBeNull();
    // The colour tints the icon rather than a dot (jsdom normalises the hex).
    expect((leads[0].querySelector('app-icon') as HTMLElement).style.color).toBeTruthy();

    expect(leads[1].querySelector('.material-symbols-outlined')).toBeNull();
    expect(leads[1].querySelector('.dot')).not.toBeNull();
  });

  it('calls editTag / deleteTag when a tag row menu action is used', () => {
    const node: TagNode = {
      tag: { id: 20, name: 'Tech', color: null, icon: null, position: 0 },
      subscriptions: [],
      unreadCount: 0,
      entryCount: 0,
    };
    const fixture = mount({ tagTree: [node] });
    const element = fixture.nativeElement as HTMLElement;
    const editTag = jest.fn();
    const deleteTag = jest.fn();
    manageActions.editTag.mockImplementation(editTag);
    manageActions.deleteTag.mockImplementation(deleteTag);

    (element.querySelector('.tag .dots') as HTMLButtonElement).click();
    fixture.detectChanges();
    const buttons = element.querySelectorAll('.tag .pop [role="menuitem"]');
    (buttons[0] as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(editTag).toHaveBeenCalledWith(node.tag);
    expect(element.querySelector('.tag .pop')).toBeNull();

    (element.querySelector('.tag .dots') as HTMLButtonElement).click();
    fixture.detectChanges();
    const buttons2 = element.querySelectorAll('.tag .pop [role="menuitem"]');
    (buttons2[1] as HTMLButtonElement).click();
    expect(deleteTag).toHaveBeenCalledWith(node.tag);
  });

  it('closes an open row menu when the pointer goes down elsewhere', () => {
    const fixture = mount({
      tagTree: [
        {
          tag: { id: 20, name: 'Tech', color: null, icon: null, position: 0 },
          subscriptions: [],
          unreadCount: 0,
          entryCount: 0,
        },
      ],
    });
    const element = fixture.nativeElement as HTMLElement;
    (element.querySelector('.tag .dots') as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(element.querySelector('.tag .pop')).not.toBeNull();

    document.body.dispatchEvent(new Event('pointerdown', { bubbles: true }));
    fixture.detectChanges();

    expect(element.querySelector('.tag .pop')).toBeNull();
  });

  it('opens only one menu when the same feed appears under two expanded tags', () => {
    const shared = sub(1, 0);
    const fixture = mount({
      tagTree: [
        {
          tag: { id: 20, name: 'Tech', color: null, icon: null, position: 0 },
          subscriptions: [shared],
          unreadCount: 0,
          entryCount: 0,
        },
        {
          tag: { id: 21, name: 'News', color: null, icon: null, position: 0 },
          subscriptions: [shared],
          unreadCount: 0,
          entryCount: 0,
        },
      ],
    });
    const element = fixture.nativeElement as HTMLElement;
    element
      .querySelectorAll<HTMLButtonElement>('.tag .chevzone')
      .forEach((chevron) => chevron.click());
    fixture.detectChanges();

    const dots = element.querySelectorAll<HTMLButtonElement>('.feedrow .dots');
    expect(dots.length).toBe(2); // the feed is rendered under both tags
    dots[0].click();
    fixture.detectChanges();

    // Distinct per-(tag,feed) keys mean only the clicked row's menu opens.
    expect(element.querySelectorAll('.pop').length).toBe(1);
  });

  describe('drag-and-drop moving', () => {
    const tag = (id: number): TagDto => ({
      id,
      name: `t${id}`,
      color: null,
      icon: null,
      position: 0,
    });
    const withTags = (subscription: SubscriptionDto, tags: TagDto[]): SubscriptionDto => ({
      ...subscription,
      tags,
    });

    function drop(
      item: SubscriptionDto,
      target: DropData,
      source: DropData = { kind: 'untagged' },
      currentIndex = 0,
    ): CdkDragDrop<DropData> {
      return {
        previousContainer: { data: source },
        container: { data: target },
        item: { data: item },
        currentIndex,
      } as unknown as CdkDragDrop<DropData>;
    }

    const onTag = (id: number): DropData => ({ kind: 'tag', tag: tag(id) });

    function moveOf(event: CdkDragDrop<DropData>) {
      const fixture = mount();
      const spy = jest.fn();
      manageActions.moveFeedToTag.mockImplementation(
        (
          sub: SubscriptionDto,
          fromTagId: number | null,
          toTagId: number | null,
          position: number | null,
        ) => spy({ sub, fromTagId, toTagId, position }),
      );
      fixture.componentInstance.onDrop(event);
      return spy;
    }

    it('moves an untagged feed into a tag at the drop index', () => {
      const spy = moveOf(drop(sub(1), onTag(3), { kind: 'untagged' }, 2));
      expect(spy).toHaveBeenCalledWith({ sub: sub(1), fromTagId: null, toTagId: 3, position: 2 });
    });

    it('moves a feed from its source tag to the target tag at the drop index', () => {
      const subscription = withTags(sub(1), [tag(3)]);
      const spy = moveOf(drop(subscription, onTag(7), onTag(3), 1));
      expect(spy).toHaveBeenCalledWith({
        sub: subscription,
        fromTagId: 3,
        toTagId: 7,
        position: 1,
      });
    });

    it('moves a feed onto the untagged list at the drop index', () => {
      const subscription = withTags(sub(1), [tag(3)]);
      const spy = moveOf(drop(subscription, { kind: 'untagged' }, onTag(3), 0));
      expect(spy).toHaveBeenCalledWith({
        sub: subscription,
        fromTagId: 3,
        toTagId: null,
        position: 0,
      });
    });

    it('appends when a feed is dropped on a tag header', () => {
      const subscription = withTags(sub(1), [tag(3)]);
      const fixture = mount();
      const spy = jest.fn();
      manageActions.moveFeedToTag.mockImplementation(
        (
          sub: SubscriptionDto,
          fromTagId: number | null,
          toTagId: number | null,
          position: number | null,
        ) => spy({ sub, fromTagId, toTagId, position }),
      );
      fixture.componentInstance.onTagHeadDrop(drop(subscription, onTag(7), onTag(3)));
      expect(spy).toHaveBeenCalledWith({
        sub: subscription,
        fromTagId: 3,
        toTagId: 7,
        position: null,
      });
    });
  });

  describe('drag-and-drop reordering', () => {
    const tagNode = (id: number, subscriptions: SubscriptionDto[] = []): TagNode => ({
      tag: { id, name: `t${id}`, color: null, icon: null, position: 0 },
      subscriptions: subscriptions,
      unreadCount: 0,
      entryCount: 0,
    });

    function reorder(
      target: DropData,
      previousIndex: number,
      currentIndex: number,
    ): CdkDragDrop<DropData> {
      const container = { data: target };
      return {
        previousContainer: container,
        container,
        previousIndex,
        currentIndex,
        item: { data: null },
      } as unknown as CdkDragDrop<DropData>;
    }

    function tagHeadDrop(dragged: TagDto, target: DropData): CdkDragDrop<DropData> {
      return {
        previousContainer: { data: { kind: 'tag', tag: dragged } },
        container: { data: target },
        item: { data: dragged },
      } as unknown as CdkDragDrop<DropData>;
    }

    it('calls reorderTags when a tag is dropped on another tag header', () => {
      const fixture = mount({ tagTree: [tagNode(10), tagNode(20), tagNode(30)] });
      const spy = jest.fn();
      manageActions.reorderTags.mockImplementation(spy);
      // Drop the last tag (30) onto the first tag's header → 30 moves to front.
      fixture.componentInstance.onTagHeadDrop(
        tagHeadDrop(tagNode(30).tag, { kind: 'tag', tag: tagNode(10).tag }),
      );
      expect(spy).toHaveBeenCalledWith([30, 10, 20]);
    });

    it('does not emit when a tag is dropped back on its own header', () => {
      const fixture = mount({ tagTree: [tagNode(10), tagNode(20)] });
      const spy = jest.fn();
      manageActions.reorderTags.mockImplementation(spy);
      fixture.componentInstance.onTagHeadDrop(
        tagHeadDrop(tagNode(10).tag, { kind: 'tag', tag: tagNode(10).tag }),
      );
      expect(spy).not.toHaveBeenCalled();
    });

    it('moves a feed onto the tag when it is dropped on the tag header', () => {
      const fixture = mount({ tagTree: [tagNode(10)] });
      const spy = jest.fn();
      manageActions.moveFeedToTag.mockImplementation(
        (
          sub: SubscriptionDto,
          fromTagId: number | null,
          toTagId: number | null,
          position: number | null,
        ) => spy({ sub, fromTagId, toTagId, position }),
      );
      const subscription = sub(1);
      fixture.componentInstance.onTagHeadDrop({
        previousContainer: { data: { kind: 'untagged' } },
        container: { data: { kind: 'tag', tag: tagNode(10).tag } },
        item: { data: subscription },
      } as unknown as CdkDragDrop<DropData>);
      expect(spy).toHaveBeenCalledWith({
        sub: subscription,
        fromTagId: null,
        toTagId: 10,
        position: null,
      });
    });

    it('calls reorderTagFeeds when a feed is reordered within its tag', () => {
      const feeds = [sub(1), sub(2), sub(3)];
      const fixture = mount({ tagTree: [tagNode(10, feeds)] });
      const spy = jest.fn();
      manageActions.reorderTagFeeds.mockImplementation((tagId: number, subscriptionIds: number[]) =>
        spy({ tagId, subscriptionIds }),
      );
      // Within tag 10, move feed at index 0 to index 2.
      fixture.componentInstance.onDrop(reorder({ kind: 'tag', tag: tagNode(10).tag }, 0, 2));
      expect(spy).toHaveBeenCalledWith({ tagId: 10, subscriptionIds: [2, 3, 1] });
    });

    it('calls reorderUntagged when an untagged feed is reordered', () => {
      const fixture = mount({ untagged: [sub(1), sub(2), sub(3)] });
      const spy = jest.fn();
      manageActions.reorderUntagged.mockImplementation(spy);
      fixture.componentInstance.onDrop(reorder({ kind: 'untagged' }, 2, 0));
      expect(spy).toHaveBeenCalledWith([3, 1, 2]);
    });

    it('does not emit when an item is dropped back at its own index', () => {
      const fixture = mount({ untagged: [sub(1), sub(2)] });
      const spy = jest.fn();
      manageActions.reorderUntagged.mockImplementation(spy);
      fixture.componentInstance.onDrop(reorder({ kind: 'untagged' }, 1, 1));
      expect(spy).not.toHaveBeenCalled();
    });
  });

  it('calls editFeed / unsubscribe for an untagged feed row', () => {
    const subscription = sub(1, 0);
    const fixture = mount({ untagged: [subscription] });
    const element = fixture.nativeElement as HTMLElement;
    const editFeed = jest.fn();
    const unsub = jest.fn();
    manageActions.editSubscription.mockImplementation(editFeed);
    manageActions.unsubscribe.mockImplementation(unsub);

    (element.querySelector('.feedrow .dots') as HTMLButtonElement).click();
    fixture.detectChanges();
    const buttons = element.querySelectorAll('.feedrow .pop [role="menuitem"]');
    (buttons[0] as HTMLButtonElement).click();
    expect(editFeed).toHaveBeenCalledWith(subscription);

    (element.querySelector('.feedrow .dots') as HTMLButtonElement).click();
    fixture.detectChanges();
    const buttons2 = element.querySelectorAll('.feedrow .pop [role="menuitem"]');
    (buttons2[buttons2.length - 1] as HTMLButtonElement).click();
    expect(unsub).toHaveBeenCalledWith(subscription);
  });

  it('shows both exclusion toggles in the untagged feed row menu and emits', () => {
    const subscription = sub(1, 0);
    const fixture = mount({ untagged: [subscription] });
    const element = fixture.nativeElement as HTMLElement;
    const toggleAllItems = jest.fn();
    const toggleForYou = jest.fn();
    manageActions.setIncludeInAllItems.mockImplementation((sub: SubscriptionDto) =>
      toggleAllItems(sub),
    );
    manageActions.setIncludeInForYou.mockImplementation((sub: SubscriptionDto) =>
      toggleForYou(sub),
    );

    (element.querySelector('.feedrow .dots') as HTMLButtonElement).click();
    fixture.detectChanges();
    const labels = [...element.querySelectorAll('.feedrow .pop [role="menuitem"]')].map((item) =>
      item.textContent?.trim(),
    );
    expect(labels).toEqual([
      'Edit feed',
      'Exclude from All items',
      'Exclude from For You',
      'Unsubscribe',
    ]);

    (
      element.querySelector('.feedrow .pop [role="menuitem"]:nth-child(2)') as HTMLButtonElement
    ).click();
    fixture.detectChanges();
    expect(toggleAllItems).toHaveBeenCalledWith(subscription);
    expect(element.querySelector('.feedrow .pop')).toBeNull();

    (element.querySelector('.feedrow .dots') as HTMLButtonElement).click();
    fixture.detectChanges();
    (
      element.querySelector('.feedrow .pop [role="menuitem"]:nth-child(3)') as HTMLButtonElement
    ).click();
    expect(toggleForYou).toHaveBeenCalledWith(subscription);
  });

  it('shows both exclusion toggles in the tagged feed row menu', () => {
    const subscription = sub(1, 0);
    const node: TagNode = {
      tag: { id: 20, name: 'Tech', color: null, icon: null, position: 0 },
      subscriptions: [subscription],
      unreadCount: 0,
      entryCount: 0,
    };
    const fixture = mount({ tagTree: [node] });
    const element = fixture.nativeElement as HTMLElement;
    (element.querySelector('.tag .chevzone') as HTMLButtonElement).click();
    fixture.detectChanges();

    const toggleAllItems = jest.fn();
    const toggleForYou = jest.fn();
    manageActions.setIncludeInAllItems.mockImplementation((sub: SubscriptionDto) =>
      toggleAllItems(sub),
    );
    manageActions.setIncludeInForYou.mockImplementation((sub: SubscriptionDto) =>
      toggleForYou(sub),
    );

    (element.querySelector('.tag-sub + .rowmenu .dots') as HTMLButtonElement).click();
    fixture.detectChanges();
    const labels = [...element.querySelectorAll('.tag-sub + .rowmenu .pop [role="menuitem"]')].map(
      (item) => item.textContent?.trim(),
    );
    expect(labels).toEqual([
      'Edit feed',
      'Exclude from All items',
      'Exclude from For You',
      'Unsubscribe',
    ]);

    (
      element.querySelector(
        '.tag-sub + .rowmenu .pop [role="menuitem"]:nth-child(2)',
      ) as HTMLButtonElement
    ).click();
    expect(toggleAllItems).toHaveBeenCalledWith(subscription);

    (element.querySelector('.tag-sub + .rowmenu .dots') as HTMLButtonElement).click();
    fixture.detectChanges();
    (
      element.querySelector(
        '.tag-sub + .rowmenu .pop [role="menuitem"]:nth-child(3)',
      ) as HTMLButtonElement
    ).click();
    expect(toggleForYou).toHaveBeenCalledWith(subscription);
  });

  it('renders the exclusion marker without displacing the unread count', () => {
    const excludedForYou = { ...sub(2, 4), includeInForYou: false };
    const fixture = mount({ untagged: [excludedForYou] });
    const element = fixture.nativeElement as HTMLElement;
    const row = element.querySelector('.feedrow')!;
    expect(row.querySelector('.feed-exclusion-marker')).not.toBeNull();
    expect(row.querySelector('.count')?.textContent).toContain('4');
  });

  it('renders the exclusion marker when only includeInAllItems is false', () => {
    const excludedAllItems = { ...sub(2, 4), includeInAllItems: false };
    const fixture = mount({ untagged: [excludedAllItems] });
    const element = fixture.nativeElement as HTMLElement;
    const row = element.querySelector('.feedrow')!;
    expect(row.querySelector('.feed-exclusion-marker')).not.toBeNull();
    expect(row.querySelector('.count')?.textContent).toContain('4');
  });

  it('renders no exclusion marker when both flags are true', () => {
    const fixture = mount({ untagged: [sub(2, 4)] });
    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('.feedrow .feed-exclusion-marker')).toBeNull();
  });

  describe('search field', () => {
    it('renders on a wide screen', () => {
      const fixture = mount({ narrow: false });
      expect(fixture.nativeElement.querySelector('app-search-field')).toBeTruthy();
    });

    it('is absent on a narrow screen, where the mobile header owns search', () => {
      const fixture = mount({ narrow: true });
      expect(fixture.nativeElement.querySelector('app-search-field')).toBeNull();
    });

    it('forwards the settled term as the search output', () => {
      const fixture = mount({ narrow: false });
      const search = jest.fn();
      fixture.componentInstance.search.subscribe(search);

      const searchField = fixture.debugElement.query(
        (de) => de.name === 'app-search-field',
      )?.componentInstance;
      searchField.search.emit('cats');

      expect(search).toHaveBeenCalledWith('cats');
    });

    it('forwards searchLoading to the field, distinct from the subscriptions loading input', () => {
      const fixture = mount({ narrow: false, searchLoading: true });

      const searchField = fixture.debugElement.query(
        (de) => de.name === 'app-search-field',
      )?.componentInstance;

      expect(searchField.loading()).toBe(true);
    });
  });

  // The build/version link, the update badge and the trial countdown moved to
  // SidebarFootComponent; they are covered by sidebar-foot.component.spec.ts.

  describe('saved searches', () => {
    it('renders no saved-searches section when the list is empty', () => {
      const fixture = mount({ savedSearches: [] });
      expect(fixture.nativeElement.textContent).not.toContain('Saved searches');
    });

    it('renders collapsed by default, showing the header with the summed unread count', () => {
      const fixture = mount({
        savedSearches: [
          {
            id: 1,
            slug: '1-climate',
            term: 'climate',
            wholeWord: false,
            phrase: false,
            position: 0,
            unreadCount: 3,
            memberCount: 3,
            includeInDigest: false,
          },
          {
            id: 2,
            slug: '2-space',
            term: 'space',
            wholeWord: false,
            phrase: false,
            position: 1,
            unreadCount: 4,
            memberCount: 4,
            includeInDigest: false,
          },
        ],
      });
      const text = fixture.nativeElement.textContent;
      expect(text).toContain('Saved searches');
      expect(text).not.toContain('climate');
      expect(fixture.nativeElement.querySelectorAll('.savedsearch-item').length).toBe(0);
      const head = fixture.nativeElement.querySelector('.savedsearch-head')!;
      expect(head.querySelector('.count')?.textContent).toContain('7');
    });

    it('expands on a chevron click, revealing the term rows while keeping the summed count', () => {
      const fixture = mount({
        savedSearches: [
          {
            id: 1,
            slug: '1-climate',
            term: 'climate',
            wholeWord: false,
            phrase: false,
            position: 0,
            unreadCount: 3,
            memberCount: 3,
            includeInDigest: false,
          },
          {
            id: 2,
            slug: '2-space',
            term: 'space',
            wholeWord: false,
            phrase: false,
            position: 1,
            unreadCount: 4,
            memberCount: 4,
            includeInDigest: false,
          },
        ],
      });
      const head: HTMLElement = fixture.nativeElement.querySelector('.savedsearch-head');
      const chevron: HTMLButtonElement = head.querySelector('.chevzone')!;
      expect(chevron.getAttribute('aria-expanded')).toBe('false');
      chevron.click();
      fixture.detectChanges();

      const text = fixture.nativeElement.textContent;
      expect(text).toContain('climate');
      expect(text).toContain('space');
      // The header keeps its summed unread count when expanded, the same way
      // a tag row keeps its own count — it does not disappear like the old
      // Task-12 behaviour.
      expect(head.querySelector('.count')?.textContent).toContain('7');
      expect(fixture.nativeElement.querySelectorAll('.savedsearch-item').length).toBe(2);
      expect(chevron.getAttribute('aria-expanded')).toBe('true');
    });

    it('navigates to the combined view instead of expanding', () => {
      const fixture = mount({
        savedSearches: [
          {
            id: 1,
            slug: '1-climate',
            term: 'climate',
            wholeWord: false,
            phrase: false,
            position: 0,
            unreadCount: 3,
            memberCount: 3,
            includeInDigest: false,
          },
        ],
      });
      const label: HTMLAnchorElement = fixture.nativeElement.querySelector('.savedsearch-toggle')!;
      expect(label.tagName).toBe('A');

      label.click();
      fixture.detectChanges();

      expect(fixture.nativeElement.querySelectorAll('.savedsearch-item').length).toBe(0);
    });

    it('expands and collapses from the chevron', () => {
      const fixture = mount({
        savedSearches: [
          {
            id: 1,
            slug: '1-climate',
            term: 'climate',
            wholeWord: false,
            phrase: false,
            position: 0,
            unreadCount: 3,
            memberCount: 3,
            includeInDigest: false,
          },
        ],
      });
      const chevron: HTMLButtonElement = fixture.nativeElement.querySelector(
        '.savedsearch-head .chevzone',
      );
      chevron.click();
      fixture.detectChanges();

      expect(fixture.nativeElement.querySelectorAll('.savedsearch-item').length).toBe(1);

      chevron.click();
      fixture.detectChanges();

      expect(fixture.nativeElement.querySelectorAll('.savedsearch-item').length).toBe(0);
    });

    it('marks the row active while the combined view is on screen', () => {
      const fixture = mount({
        savedSearches: [
          {
            id: 1,
            slug: '1-climate',
            term: 'climate',
            wholeWord: false,
            phrase: false,
            position: 0,
            unreadCount: 3,
            memberCount: 3,
            includeInDigest: false,
          },
        ],
        selection: { kind: 'saved-searches', id: null, unread: false },
      });

      expect(fixture.nativeElement.querySelector('.savedsearch-toggle')!.classList).toContain(
        'active',
      );
    });

    it('also expands on a click of its trailing chevron button', () => {
      const fixture = mount({
        savedSearches: [
          {
            id: 1,
            slug: '1-climate',
            term: 'climate',
            wholeWord: false,
            phrase: false,
            position: 0,
            unreadCount: 3,
            memberCount: 3,
            includeInDigest: false,
          },
        ],
      });
      const chevron: HTMLButtonElement = fixture.nativeElement.querySelector(
        '.savedsearch-head .chevzone',
      );
      chevron.click();
      fixture.detectChanges();

      expect(fixture.nativeElement.textContent).toContain('climate');
    });

    it('places the chevron in the same right-edge column as a tag chevron', () => {
      const node: TagNode = {
        tag: { id: 30, name: 'Tech', color: null, icon: null, position: 0 },
        subscriptions: [],
        unreadCount: 0,
        entryCount: 0,
      };
      const fixture = mount({
        tagTree: [node],
        savedSearches: [
          {
            id: 1,
            slug: '1-climate',
            term: 'climate',
            wholeWord: false,
            phrase: false,
            position: 0,
            unreadCount: 3,
            memberCount: 3,
            includeInDigest: false,
          },
        ],
      });
      const savedChev: HTMLElement = fixture.nativeElement.querySelector(
        '.savedsearch-head .chevzone',
      );
      const tagChev: HTMLElement = fixture.nativeElement.querySelector('.taghead .chevzone');
      expect(savedChev.className).toBe(tagChev.className);
    });

    // The header chevron follows the same convention as the Tags and Feeds
    // section chevrons: it points down (`expand_more`) when the list is open
    // and right (`chevron_right`) when it is collapsed — never up.
    it('points the header chevron down when expanded and right when collapsed', () => {
      const fixture = mount({
        savedSearches: [
          {
            id: 1,
            slug: '1-climate',
            term: 'climate',
            wholeWord: false,
            phrase: false,
            position: 0,
            unreadCount: 3,
            memberCount: 3,
            includeInDigest: false,
          },
        ],
      });
      const chevronIcon = (): string | null =>
        fixture.nativeElement.querySelector(
          '.savedsearch-head .chevzone .material-symbols-outlined',
        )?.textContent ?? null;

      expect(chevronIcon()).toBe('chevron_right');

      fixture.componentInstance.toggleSavedSearches();
      fixture.detectChanges();

      expect(chevronIcon()).toBe('expand_more');
    });

    it('shows a compact "W" pill on a whole-word row and none on a plain row', () => {
      const fixture = mount({
        savedSearches: [
          {
            id: 1,
            slug: '1-climate',
            term: 'climate',
            wholeWord: true,
            phrase: false,
            position: 0,
            unreadCount: 0,
            memberCount: 0,
            includeInDigest: false,
          },
          {
            id: 2,
            slug: '2-space',
            term: 'space',
            wholeWord: false,
            phrase: false,
            position: 1,
            unreadCount: 0,
            memberCount: 0,
            includeInDigest: false,
          },
        ],
      });
      fixture.componentInstance.toggleSavedSearches();
      fixture.detectChanges();

      const items = [...fixture.nativeElement.querySelectorAll('.savedsearch-item')];
      const wholeWordRow = items.find((item) => item.textContent?.includes('climate'))!;
      const plainRow = items.find((item) => item.textContent?.includes('space'))!;

      const badge = wholeWordRow.querySelector('.whole-word-badge')!;
      expect(badge.textContent?.trim()).toBe('W');
      expect(wholeWordRow.querySelector('.sr-only')?.textContent).toContain('Whole words');
      expect(plainRow.querySelector('.whole-word-badge')).toBeNull();
    });

    it('shows a compact "P" pill on a phrase row, with no quotes on the term', () => {
      const fixture = mount({
        savedSearches: [
          {
            id: 1,
            slug: '1-climate-change',
            term: 'climate change',
            wholeWord: false,
            phrase: true,
            position: 0,
            unreadCount: 0,
            memberCount: 0,
            includeInDigest: false,
          },
        ],
      });
      fixture.componentInstance.toggleSavedSearches();
      fixture.detectChanges();

      const row = fixture.nativeElement.querySelector('.savedsearch-item')!;
      expect(row.querySelector('.phrase-badge')?.textContent?.trim()).toBe('P');
      expect(row.querySelector('.sr-only')?.textContent).toContain('Phrase');
      // The mode rides in the pill, so the stored bare term shows without quotes.
      expect(row.querySelector('.saved-term')?.textContent?.trim()).toBe('climate change');
      expect(row.querySelector('.whole-word-badge')).toBeNull();
    });

    // The active row is decided by id, handed down by the shell. The sidebar
    // does NOT re-encode a term to string-match it against the selection: that
    // was a second, subtly different identity rule, and it disagreed with the
    // shell's whenever the whole-word signal was a tab or a no-break space.
    it('marks the row the shell names active, and only that one', () => {
      const fixture = mount({
        savedSearches: [
          {
            id: 1,
            slug: '1-climate',
            term: 'climate',
            wholeWord: true,
            phrase: false,
            position: 0,
            unreadCount: 0,
            memberCount: 0,
            includeInDigest: false,
          },
          {
            id: 2,
            slug: '2-space',
            term: 'space',
            wholeWord: false,
            phrase: false,
            position: 1,
            unreadCount: 0,
            memberCount: 0,
            includeInDigest: false,
          },
        ],
        activeSavedSearchId: 1,
      });
      fixture.componentInstance.toggleSavedSearches();
      fixture.detectChanges();

      const items = [...fixture.nativeElement.querySelectorAll('.savedsearch-item')];
      const active = items.filter((item) => item.classList.contains('active'));
      expect(active).toHaveLength(1);
      expect(active[0].textContent).toContain('climate');
    });

    it('marks no row active when the shell names none', () => {
      const fixture = mount({
        savedSearches: [
          {
            id: 1,
            slug: '1-climate',
            term: 'climate',
            wholeWord: true,
            phrase: false,
            position: 0,
            unreadCount: 0,
            memberCount: 0,
            includeInDigest: false,
          },
        ],
      });
      fixture.componentInstance.toggleSavedSearches();
      fixture.detectChanges();

      expect(fixture.nativeElement.querySelector('.savedsearch-item.active')).toBeNull();
    });

    it('links a saved search row to its slug path', () => {
      const fixture = mount({
        savedSearches: [
          {
            id: 42,
            slug: '42-climate',
            term: 'climate',
            wholeWord: false,
            phrase: false,
            position: 0,
            unreadCount: 0,
            memberCount: 0,
            includeInDigest: false,
          },
        ],
      });
      fixture.componentInstance.toggleSavedSearches();
      fixture.detectChanges();

      const row: HTMLAnchorElement = fixture.nativeElement.querySelector('.savedsearch-item');
      expect(row.getAttribute('href')).toContain('/searches/saved/42-climate');
    });

    it('links the saved-searches header to the combined path', () => {
      const fixture = mount({
        savedSearches: [
          {
            id: 1,
            slug: '1-climate',
            term: 'climate',
            wholeWord: false,
            phrase: false,
            position: 0,
            unreadCount: 0,
            memberCount: 0,
            includeInDigest: false,
          },
        ],
      });
      const head: HTMLAnchorElement = fixture.nativeElement.querySelector('.savedsearch-toggle');
      expect(head.getAttribute('href')).toContain('/searches/saved/all');
    });

    const openSaved = (fixture: ReturnType<typeof mount>) => {
      (
        fixture.nativeElement.querySelector('.savedsearch-head .chevzone') as HTMLButtonElement
      ).click();
      fixture.detectChanges();
    };
    const terms = (fixture: ReturnType<typeof mount>) =>
      Array.from(fixture.nativeElement.querySelectorAll('.savedsearch-item .saved-term')).map(
        (term) => (term as HTMLElement).textContent?.trim(),
      );
    const saved = (id: number, term: string, unreadCount: number): SavedSearchDto => ({
      id,
      slug: `${id}-saved`,
      term,
      wholeWord: false,
      phrase: false,
      position: 0,
      unreadCount,
      memberCount: unreadCount,
      includeInDigest: false,
    });

    it('orders saved searches unread-first, then by id descending', () => {
      const fixture = mount({
        savedSearches: [
          saved(1, 'oldest', 0),
          saved(2, 'busy', 5),
          saved(3, 'quiet', 0),
          saved(4, 'busier', 5),
        ],
      });
      openSaved(fixture);
      // unread>0 first, by count desc then id desc: busier(4,5), busy(2,5) -> id desc; then quiet(3,0), oldest(1,0)
      expect(terms(fixture)).toEqual(['busier', 'busy', 'quiet', 'oldest']);
    });

    it('keeps the frozen order when a count drops (no reshuffle on read)', () => {
      const fixture = mount({ savedSearches: [saved(1, 'a', 3), saved(2, 'b', 5)] });
      openSaved(fixture);
      expect(terms(fixture)).toEqual(['b', 'a']); // 5 before 3
      // A read drops b's count below a's; the order must stay frozen while open.
      fixture.componentRef.setInput('savedSearches', [saved(1, 'a', 3), saved(2, 'b', 1)]);
      fixture.detectChanges();
      expect(terms(fixture)).toEqual(['b', 'a']);
    });

    it('re-ranks on the next section open', () => {
      const fixture = mount({ savedSearches: [saved(1, 'a', 3), saved(2, 'b', 5)] });
      openSaved(fixture); // b, a
      fixture.componentRef.setInput('savedSearches', [saved(1, 'a', 3), saved(2, 'b', 1)]);
      fixture.detectChanges();
      openSaved(fixture); // close
      openSaved(fixture); // open again -> re-rank: a(3) before b(1)
      expect(terms(fixture)).toEqual(['a', 'b']);
    });

    it('re-ranks immediately when a saved search is deleted (structural change)', () => {
      const fixture = mount({
        savedSearches: [saved(1, 'a', 3), saved(2, 'b', 5), saved(3, 'c', 4)],
      });
      openSaved(fixture); // b(5), c(4), a(3)
      fixture.componentRef.setInput('savedSearches', [saved(1, 'a', 3), saved(3, 'c', 4)]);
      fixture.detectChanges();
      expect(terms(fixture)).toEqual(['c', 'a']); // c(4) before a(3), no stale b
    });

    const many = Array.from({ length: 8 }, (_, index) =>
      saved(index + 1, `s${index + 1}`, 8 - index),
    );
    // counts 8..1, so id 1 (count 8) ... id 8 (count 1): already ranked by both keys.

    it('shows only six rows and a "Show more" link when there are more than six', () => {
      const fixture = mount({ savedSearches: many });
      openSaved(fixture);
      expect(fixture.nativeElement.querySelectorAll('.savedsearch-item').length).toBe(6);
      const more = fixture.nativeElement.querySelector('.savedsearch-more') as HTMLButtonElement;
      expect(more).not.toBeNull();
      expect(more.textContent).toContain('Show 2 more');
    });

    it('reveals the full list on "Show more" and collapses again on "Show less"', () => {
      const fixture = mount({ savedSearches: many });
      openSaved(fixture);
      (fixture.nativeElement.querySelector('.savedsearch-more') as HTMLButtonElement).click();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelectorAll('.savedsearch-item').length).toBe(8);
      expect(
        (fixture.nativeElement.querySelector('.savedsearch-more') as HTMLElement).textContent,
      ).toContain('Show less');
      (fixture.nativeElement.querySelector('.savedsearch-more') as HTMLButtonElement).click();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelectorAll('.savedsearch-item').length).toBe(6);
    });

    it('shows no "Show more" link at exactly six saved searches', () => {
      const fixture = mount({ savedSearches: many.slice(0, 6) });
      openSaved(fixture);
      expect(fixture.nativeElement.querySelectorAll('.savedsearch-item').length).toBe(6);
      expect(fixture.nativeElement.querySelector('.savedsearch-more')).toBeNull();
    });

    it('resets to the top six when the section is re-opened after expanding', () => {
      const fixture = mount({ savedSearches: many });
      openSaved(fixture);
      (fixture.nativeElement.querySelector('.savedsearch-more') as HTMLButtonElement).click();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelectorAll('.savedsearch-item').length).toBe(8);
      openSaved(fixture); // close
      openSaved(fixture); // open again
      expect(fixture.nativeElement.querySelectorAll('.savedsearch-item').length).toBe(6);
    });

    it('pins the active saved search as an extra row when it is outside the top six', () => {
      const fixture = mount({ savedSearches: many, activeSavedSearchId: 8 }); // id 8 has the lowest count -> last
      openSaved(fixture);
      const rows = fixture.nativeElement.querySelectorAll('.savedsearch-item');
      expect(rows.length).toBe(7); // top 6 + the pinned active
      const last = rows[rows.length - 1] as HTMLElement;
      expect(last.querySelector('.saved-term')?.textContent?.trim()).toBe('s8');
      expect(last.classList).toContain('active');
    });

    it('does not pin when the active search is already in the top six', () => {
      const fixture = mount({ savedSearches: many, activeSavedSearchId: 1 }); // id 1 has the highest count -> first
      openSaved(fixture);
      expect(fixture.nativeElement.querySelectorAll('.savedsearch-item').length).toBe(6);
    });

    it('shows no duplicate pinned row once the list is expanded', () => {
      const fixture = mount({ savedSearches: many, activeSavedSearchId: 8 });
      openSaved(fixture);
      (fixture.nativeElement.querySelector('.savedsearch-more') as HTMLButtonElement).click();
      fixture.detectChanges();
      const rows = Array.from(
        fixture.nativeElement.querySelectorAll('.savedsearch-item .saved-term'),
      ).map((term) => (term as HTMLElement).textContent?.trim());
      expect(rows.length).toBe(8);
      expect(rows.filter((term) => term === 's8').length).toBe(1);
    });

    it('excludes the pinned active row from the hidden count', () => {
      const fixture = mount({ savedSearches: many, activeSavedSearchId: 8 });
      openSaved(fixture);
      // 8 total, 6 in the top + 1 pinned active on screen -> 1 hidden.
      expect(
        (fixture.nativeElement.querySelector('.savedsearch-more') as HTMLElement).textContent,
      ).toContain('Show 1 more');
    });

    it('shows a downward chevron on the "Show more" link and an upward one on "Show less"', () => {
      const fixture = mount({ savedSearches: many });
      openSaved(fixture);
      const moreButton = fixture.nativeElement.querySelector('.savedsearch-more') as HTMLElement;
      const moreIcon = moreButton.querySelector('app-icon') as HTMLElement;
      expect(moreIcon).not.toBeNull();
      expect(moreIcon.textContent).toContain('expand_more');

      moreButton.click();
      fixture.detectChanges();
      const lessButton = fixture.nativeElement.querySelector('.savedsearch-more') as HTMLElement;
      const lessIcon = lessButton.querySelector('app-icon') as HTMLElement;
      expect(lessIcon).not.toBeNull();
      expect(lessIcon.textContent).toContain('expand_less');
    });

    it('does not pin when the active id is absent from the saved-search list', () => {
      const fixture = mount({ savedSearches: many, activeSavedSearchId: 999 });
      openSaved(fixture);
      expect(fixture.nativeElement.querySelectorAll('.savedsearch-item').length).toBe(6);
    });
  });

  describe('per-search digest toggle', () => {
    const climate: SavedSearchDto = {
      id: 1,
      slug: '1-climate',
      term: 'climate',
      wholeWord: false,
      phrase: false,
      position: 0,
      unreadCount: 3,
      memberCount: 3,
      includeInDigest: false,
    };
    const space: SavedSearchDto = {
      id: 2,
      slug: '2-space',
      term: 'space',
      wholeWord: false,
      phrase: false,
      position: 1,
      unreadCount: 4,
      memberCount: 4,
      includeInDigest: true,
    };

    it('renders no mail icon on saved-search rows when mail is disabled', () => {
      const fixture = mount({ savedSearches: [climate], mailEnabled: false });
      fixture.componentInstance.toggleSavedSearches();
      fixture.detectChanges();

      expect(fixture.nativeElement.querySelector('.digest-toggle')).toBeNull();
    });

    it('renders no mail icon when mail is on but the account digest is off', () => {
      const fixture = mount({ savedSearches: [climate], mailEnabled: true, digestEnabled: false });
      fixture.componentInstance.toggleSavedSearches();
      fixture.detectChanges();

      expect(fixture.nativeElement.querySelector('.digest-toggle')).toBeNull();
    });

    it('renders a mail icon button per row when mail is enabled, muted only when not included', () => {
      const fixture = mount({ savedSearches: [climate, space], mailEnabled: true });
      fixture.componentInstance.toggleSavedSearches();
      fixture.detectChanges();

      const buttons: HTMLButtonElement[] = [
        ...fixture.nativeElement.querySelectorAll('.digest-toggle'),
      ];
      expect(buttons).toHaveLength(2);

      const climateRow = buttons.find((button) =>
        button.getAttribute('aria-label')?.includes('climate'),
      )!;
      const spaceRow = buttons.find((button) =>
        button.getAttribute('aria-label')?.includes('space'),
      )!;

      expect(climateRow.getAttribute('aria-pressed')).toBe('false');
      expect(climateRow.querySelector('app-icon')?.classList.contains('muted')).toBe(true);

      expect(spaceRow.getAttribute('aria-pressed')).toBe('true');
      expect(spaceRow.querySelector('app-icon')?.classList.contains('muted')).toBe(false);
    });

    it('emits toggleDigest with the row on click, without navigating the row link', () => {
      const fixture = mount({ savedSearches: [climate], mailEnabled: true });
      fixture.componentInstance.toggleSavedSearches();
      fixture.detectChanges();

      const emitted: SavedSearchDto[] = [];
      fixture.componentInstance.toggleDigest.subscribe((row) => emitted.push(row));

      const button: HTMLButtonElement = fixture.nativeElement.querySelector('.digest-toggle');
      const clickEvent = new MouseEvent('click', { bubbles: true, cancelable: true });
      const stopSpy = jest.spyOn(clickEvent, 'stopPropagation');
      const preventSpy = jest.spyOn(clickEvent, 'preventDefault');
      button.dispatchEvent(clickEvent);
      fixture.detectChanges();

      expect(emitted).toHaveLength(1);
      expect(emitted[0]).toEqual(climate);
      expect(stopSpy).toHaveBeenCalled();
      expect(preventSpy).toHaveBeenCalled();
    });
  });

  describe('collapsible tag list', () => {
    const tagNode: TagNode = {
      tag: { id: 40, name: 'Tech', color: null, icon: null, position: 0 },
      subscriptions: [sub(1, 3)],
      unreadCount: 3,
      entryCount: 0,
    };

    // The collapsed state persists in localStorage, so each test starts and
    // ends from a clean slate to keep the default-expanded assumption honest.
    beforeEach(() => localStorage.clear());
    afterEach(() => localStorage.clear());

    // The borderless header chevron is its own `.section-chevron`, never the
    // bordered `.chevzone` box the tag rows carry.
    const headChevronIcon = (element: HTMLElement) =>
      element.querySelector('.tags-head .section-chevron .material-symbols-outlined')!.textContent;

    it('shows the tags expanded by default, with a downward chevron on the header', () => {
      const element = mount({ tagTree: [tagNode] }).nativeElement as HTMLElement;
      const head = element.querySelector('.tags-head')!;
      expect(head.textContent).toContain('Tags');
      expect(head.querySelector('.section-toggle')!.getAttribute('aria-expanded')).toBe('true');
      expect(headChevronIcon(element)).toBe('expand_more');
      expect(head.querySelector('.chevzone')).toBeNull();
      expect(element.querySelector('.tags .taghead')).not.toBeNull();
    });

    it('collapses the list and points the chevron right when the title is clicked', () => {
      const fixture = mount({ tagTree: [tagNode] });
      const element = fixture.nativeElement as HTMLElement;
      (element.querySelector('.tags-head .section-toggle') as HTMLButtonElement).click();
      fixture.detectChanges();

      expect(
        element.querySelector('.tags-head .section-toggle')!.getAttribute('aria-expanded'),
      ).toBe('false');
      expect(headChevronIcon(element)).toBe('chevron_right');
      expect(element.querySelector('.tags .taghead')).toBeNull();
      // The header itself stays put so the section can be reopened.
      expect(element.querySelector('.tags-head')).not.toBeNull();
    });

    it('also collapses via the trailing chevron button', () => {
      const fixture = mount({ tagTree: [tagNode] });
      const element = fixture.nativeElement as HTMLElement;
      (element.querySelector('.tags-head .section-chevron') as HTMLButtonElement).click();
      fixture.detectChanges();

      expect(element.querySelector('.tags .taghead')).toBeNull();
    });

    it('restores the collapsed state on a fresh mount (persisted)', () => {
      const first = mount({ tagTree: [tagNode] });
      first.componentInstance.toggleTags();

      TestBed.resetTestingModule();
      const element = mount({ tagTree: [tagNode] }).nativeElement as HTMLElement;
      expect(
        element.querySelector('.tags-head .section-toggle')!.getAttribute('aria-expanded'),
      ).toBe('false');
      expect(element.querySelector('.tags .taghead')).toBeNull();
    });
  });

  describe('collapsible feed list', () => {
    beforeEach(() => localStorage.clear());
    afterEach(() => localStorage.clear());

    const headChevronIcon = (element: HTMLElement) =>
      element.querySelector('.feeds-head .section-chevron .material-symbols-outlined')!.textContent;

    it('shows the feeds expanded by default, with a downward chevron on the header', () => {
      const element = mount({ untagged: [sub(1, 2)] }).nativeElement as HTMLElement;
      const head = element.querySelector('.feeds-head')!;
      expect(head.textContent).toContain('Feeds');
      expect(head.querySelector('.section-toggle')!.getAttribute('aria-expanded')).toBe('true');
      expect(headChevronIcon(element)).toBe('expand_more');
      expect(element.querySelector('.feedlist .feedrow')).not.toBeNull();
    });

    it('collapses the untagged feeds and points the chevron right when clicked', () => {
      const fixture = mount({ untagged: [sub(1, 2)] });
      const element = fixture.nativeElement as HTMLElement;
      (element.querySelector('.feeds-head .section-toggle') as HTMLButtonElement).click();
      fixture.detectChanges();

      expect(headChevronIcon(element)).toBe('chevron_right');
      expect(element.querySelector('.feedlist .feedrow')).toBeNull();
      // The drop list itself stays mounted so an untag drag still has a target.
      expect(element.querySelector('.feedlist')).not.toBeNull();
    });

    it('also collapses via the trailing chevron button', () => {
      const fixture = mount({ untagged: [sub(1, 2)] });
      const element = fixture.nativeElement as HTMLElement;
      (element.querySelector('.feeds-head .section-chevron') as HTMLButtonElement).click();
      fixture.detectChanges();

      expect(element.querySelector('.feedlist .feedrow')).toBeNull();
    });

    it('reveals the feeds while a drag is in progress, even when collapsed', () => {
      const fixture = mount({ untagged: [sub(1, 2)] });
      const element = fixture.nativeElement as HTMLElement;
      fixture.componentInstance.toggleFeeds();
      fixture.detectChanges();
      expect(element.querySelector('.feedlist .feedrow')).toBeNull();

      fixture.componentInstance.dragging.set(true);
      fixture.detectChanges();
      expect(element.querySelector('.feedlist .feedrow')).not.toBeNull();
    });

    it('restores the collapsed state on a fresh mount (persisted)', () => {
      const first = mount({ untagged: [sub(1, 2)] });
      first.componentInstance.toggleFeeds();

      TestBed.resetTestingModule();
      const element = mount({ untagged: [sub(1, 2)] }).nativeElement as HTMLElement;
      expect(
        element.querySelector('.feeds-head .section-toggle')!.getAttribute('aria-expanded'),
      ).toBe('false');
      expect(element.querySelector('.feedlist .feedrow')).toBeNull();
    });
  });
});

describe('for-you row', () => {
  // AiAvailabilityService and RecommendationsService are faked with plain
  // signals — structural typing accepts them in place of the readonly ones
  // the real services expose.
  function mountWithAi(ready: boolean, running = false, forYouCount = 0) {
    TestBed.configureTestingModule({
      imports: [SidebarComponent, provideTranslocoTesting()],
      providers: [
        { provide: ManageActions, useValue: manageActions },
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: AuthService, useValue: { user: signal(account(null)) } },
        { provide: LayoutService, useValue: { isCoarse: signal(false), isNarrow: signal(false) } },
        { provide: ActionSheet, useValue: { open: jest.fn(() => of(undefined)) } },
        { provide: AiAvailabilityService, useValue: { ready: signal(ready) } },
        {
          provide: RecommendationsService,
          useValue: { running: signal(running), forYouCount: signal(forYouCount) },
        },
      ],
    });
    const fixture = TestBed.createComponent(SidebarComponent);
    fixture.componentRef.setInput('tagTree', []);
    fixture.componentRef.setInput('untagged', []);
    fixture.componentRef.setInput('totalUnread', 0);
    fixture.componentRef.setInput('selection', { kind: 'all', id: null, unread: true });
    fixture.componentRef.setInput('loading', false);
    fixture.componentRef.setInput('organising', false);
    fixture.detectChanges();
    return fixture;
  }

  it('is absent when AI is not ready', () => {
    const element = mountWithAi(false).nativeElement as HTMLElement;
    expect(element.querySelector('.nav.for-you')).toBeNull();
  });

  it('is present when AI is ready', () => {
    const element = mountWithAi(true).nativeElement as HTMLElement;
    expect(element.querySelector('.nav.for-you')).not.toBeNull();
  });

  it('pulses the icon while a recommendation run is in progress', () => {
    const element = mountWithAi(true, true).nativeElement as HTMLElement;
    expect(element.querySelector('.nav.for-you app-icon.pulse')).not.toBeNull();
  });

  it('shows the for-you item count as a badge', () => {
    const element = mountWithAi(true, false, 12).nativeElement as HTMLElement;
    expect(element.querySelector('.nav.for-you .count')!.textContent).toContain('12');
  });

  it('hides the badge when the for-you list is empty', () => {
    const element = mountWithAi(true, false, 0).nativeElement as HTMLElement;
    expect(element.querySelector('.nav.for-you .count')).toBeNull();
  });
});

describe('organise mode', () => {
  const tag: TagDto = { id: 1, name: 'News', color: null, icon: null, position: 0 };
  const tree: TagNode[] = [{ tag, subscriptions: [sub(5)], unreadCount: 3, entryCount: 0 }];

  it('offers the Organise switch on coarse pointers only', () => {
    const isCoarse = signal(false);
    TestBed.configureTestingModule({
      imports: [SidebarComponent, provideTranslocoTesting()],
      providers: [
        { provide: ManageActions, useValue: manageActions },
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: AuthService, useValue: { user: signal(account(null)) } },
        { provide: LayoutService, useValue: { isCoarse, isNarrow: signal(false) } },
      ],
    });
    const fixture = TestBed.createComponent(SidebarComponent);
    fixture.componentRef.setInput('tagTree', []);
    fixture.componentRef.setInput('untagged', []);
    fixture.componentRef.setInput('totalUnread', 0);
    fixture.componentRef.setInput('selection', { kind: 'all', id: null, unread: true });
    fixture.componentRef.setInput('loading', false);
    fixture.componentRef.setInput('organising', false);
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('.organise')).toBeNull();

    isCoarse.set(true);
    fixture.detectChanges();
    const organiseSwitch = element.querySelector('.organise')!;
    expect(organiseSwitch.getAttribute('role')).toBe('switch');
    expect(organiseSwitch.getAttribute('aria-checked')).toBe('false');
    expect(organiseSwitch.textContent).toContain('Organise');
  });

  it('clicking the switch flips the organising model', () => {
    const fixture = mount({ coarse: true });
    (fixture.nativeElement as HTMLElement).querySelector<HTMLElement>('.organise')!.click();
    fixture.detectChanges();
    expect(fixture.componentInstance.organising()).toBe(true);
    expect(
      (fixture.nativeElement as HTMLElement)
        .querySelector('.organise')!
        .getAttribute('aria-checked'),
    ).toBe('true');
  });

  it('organising hides the actions, global views, view controls and trial line', () => {
    const element = mount({
      coarse: true,
      organising: true,
      tagTree: tree,
      user: account(inDays(5)),
    }).nativeElement as HTMLElement;
    expect(element.querySelector('.actions')).toBeNull();
    expect(element.querySelector('.nav.all')).toBeNull();
    expect(element.querySelector('app-view-controls')).toBeNull();
    expect(element.querySelector('.trial')).toBeNull();
    expect(element.querySelector('.version')).not.toBeNull();
    expect(element.querySelector('.tags')).not.toBeNull();
  });

  it('navigation mode keeps all of them', () => {
    const element = mount({
      coarse: true,
      tagTree: tree,
      user: account(inDays(5)),
    }).nativeElement as HTMLElement;
    expect(element.querySelector('.actions')).not.toBeNull();
    expect(element.querySelector('.nav.all')).not.toBeNull();
    expect(element.querySelector('app-view-controls')).not.toBeNull();
    expect(element.querySelector('.trial')).not.toBeNull();
  });

  it('organising always shows the Feeds label as the untag drop target', () => {
    const element = mount({ coarse: true, organising: true, untagged: [] })
      .nativeElement as HTMLElement;
    expect(element.textContent).toContain('Feeds');
  });

  it('coarse navigation shows the trailing chevron and no inline menu', () => {
    const element = mount({ coarse: true, tagTree: tree }).nativeElement as HTMLElement;
    const zone = element.querySelector('.tag .chevzone')!;
    expect(zone).not.toBeNull();
    expect(zone.getAttribute('aria-expanded')).toBe('false');
    expect(element.querySelector('.tag .nav.grow')).not.toBeNull();
    expect(element.querySelector('.dots')).toBeNull();
  });

  it('the chevron zone expands the tag without navigating', () => {
    const fixture = mount({ coarse: true, tagTree: tree });
    const element = fixture.nativeElement as HTMLElement;
    element.querySelector<HTMLElement>('.tag .chevzone')!.click();
    fixture.detectChanges();
    expect(element.querySelector('.tagfeeds')).not.toBeNull();
    expect(element.querySelector('.tag .chevzone')!.getAttribute('aria-expanded')).toBe('true');
    expect(TestBed.inject(Router).url).toBe('/'); // expand must not select the tag
  });

  it('organise rows carry a drag handle and expand via the row body', () => {
    const fixture = mount({ coarse: true, organising: true, tagTree: tree });
    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('.tag .handle')).not.toBeNull();
    expect(element.querySelector('.tag .nav.grow')).toBeNull();
    expect(element.querySelector('.tag .chevzone')).toBeNull();
    expect(element.querySelector('.tag .rowbody')!.getAttribute('aria-expanded')).toBe('false');
    element.querySelector<HTMLElement>('.tag .rowbody')!.click();
    fixture.detectChanges();
    expect(element.querySelector('.tag .rowbody')!.getAttribute('aria-expanded')).toBe('true');
    expect(element.querySelector('.tagfeeds')).not.toBeNull();
    expect(element.querySelector('.tagfeeds .handle')).not.toBeNull();
  });

  it('the tag dots open the action sheet and route the choice', () => {
    const fixture = mount({ coarse: true, organising: true, tagTree: tree, sheetChoice: 'delete' });
    const deleted = jest.fn();
    manageActions.deleteTag.mockImplementation(deleted);
    fixture.nativeElement.querySelector('.tag .dots').click();
    const sheet = TestBed.inject(ActionSheet);
    expect(sheet.open).toHaveBeenCalledWith({
      title: 'News',
      actions: [
        { id: 'edit', label: 'Edit tag' },
        { id: 'delete', label: 'Delete tag', danger: true },
      ],
    });
    expect(deleted).toHaveBeenCalledWith(tag);
  });

  it('the feed dots offer edit, the two exclusion toggles, and unsubscribe', () => {
    const fixture = mount({
      coarse: true,
      organising: true,
      untagged: [sub(9)],
      sheetChoice: 'edit',
    });
    const edited = jest.fn();
    manageActions.editSubscription.mockImplementation(edited);
    fixture.nativeElement.querySelector('.feedrow .dots').click();
    const sheet = TestBed.inject(ActionSheet);
    expect(sheet.open).toHaveBeenCalledWith({
      title: 's9',
      actions: [
        { id: 'edit', label: 'Edit feed' },
        { id: 'toggleAllItems', label: 'Exclude from All items' },
        { id: 'toggleForYou', label: 'Exclude from For You' },
        { id: 'unsubscribe', label: 'Unsubscribe', danger: true },
      ],
    });
    expect(edited).toHaveBeenCalledWith(expect.objectContaining({ id: 9 }));
  });

  it('routes the feed sheet toggle choices to toggleAllItems / toggleForYou and only those', () => {
    const fixture = mount({
      coarse: true,
      organising: true,
      untagged: [sub(9)],
      sheetChoice: 'toggleAllItems',
    });
    const toggleAllItems = jest.fn();
    const toggleForYou = jest.fn();
    const unsubscribed = jest.fn();
    manageActions.setIncludeInAllItems.mockImplementation((sub: SubscriptionDto) =>
      toggleAllItems(sub),
    );
    manageActions.setIncludeInForYou.mockImplementation((sub: SubscriptionDto) =>
      toggleForYou(sub),
    );
    manageActions.unsubscribe.mockImplementation(unsubscribed);
    fixture.nativeElement.querySelector('.feedrow .dots').click();
    expect(toggleAllItems).toHaveBeenCalledWith(expect.objectContaining({ id: 9 }));
    expect(toggleForYou).not.toHaveBeenCalled();
    expect(unsubscribed).not.toHaveBeenCalled();
  });

  it('routes the tag edit choice to editTag and only that', () => {
    const fixture = mount({ coarse: true, organising: true, tagTree: tree, sheetChoice: 'edit' });
    const edited = jest.fn();
    const deleted = jest.fn();
    manageActions.editTag.mockImplementation(edited);
    manageActions.deleteTag.mockImplementation(deleted);
    fixture.nativeElement.querySelector('.tag .dots').click();
    expect(edited).toHaveBeenCalledWith(tag);
    expect(deleted).not.toHaveBeenCalled();
  });

  it('routes the feed unsubscribe choice to unsubscribe and only that', () => {
    const fixture = mount({
      coarse: true,
      organising: true,
      untagged: [sub(9)],
      sheetChoice: 'unsubscribe',
    });
    const unsubscribed = jest.fn();
    const edited = jest.fn();
    manageActions.unsubscribe.mockImplementation(unsubscribed);
    manageActions.editSubscription.mockImplementation(edited);
    fixture.nativeElement.querySelector('.feedrow .dots').click();
    expect(unsubscribed).toHaveBeenCalledWith(expect.objectContaining({ id: 9 }));
    expect(edited).not.toHaveBeenCalled();
  });

  it('a dismissed sheet emits nothing', () => {
    const fixture = mount({
      coarse: true,
      organising: true,
      tagTree: tree,
      sheetChoice: undefined,
    });
    const emitted = jest.fn();
    manageActions.editTag.mockImplementation(emitted);
    manageActions.deleteTag.mockImplementation(emitted);
    fixture.nativeElement.querySelector('.tag .dots').click();
    expect(TestBed.inject(ActionSheet).open).toHaveBeenCalled();
    expect(emitted).not.toHaveBeenCalled();
  });

  it('resets organising when the pointer stops being coarse', () => {
    const isCoarse = signal(true);
    TestBed.configureTestingModule({
      imports: [SidebarComponent, provideTranslocoTesting()],
      providers: [
        { provide: ManageActions, useValue: manageActions },
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: AuthService, useValue: { user: signal(account(null)) } },
        { provide: LayoutService, useValue: { isCoarse, isNarrow: signal(false) } },
        { provide: ActionSheet, useValue: { open: jest.fn(() => of(undefined)) } },
      ],
    });
    const fixture = TestBed.createComponent(SidebarComponent);
    fixture.componentRef.setInput('tagTree', tree);
    fixture.componentRef.setInput('untagged', []);
    fixture.componentRef.setInput('totalUnread', 0);
    fixture.componentRef.setInput('selection', { kind: 'all', id: null, unread: true });
    fixture.componentRef.setInput('loading', false);
    fixture.componentRef.setInput('organising', true);
    fixture.detectChanges();
    expect((fixture.nativeElement as HTMLElement).querySelector('.tag .handle')).not.toBeNull();

    isCoarse.set(false);
    fixture.detectChanges();
    // The exit switch only renders on coarse pointers, so a stuck true would
    // leave the organise DOM with no way out — the component resets instead.
    expect(fixture.componentInstance.organising()).toBe(false);
    expect((fixture.nativeElement as HTMLElement).querySelector('.tag .handle')).toBeNull();
    expect((fixture.nativeElement as HTMLElement).querySelector('.tag .chevzone')).not.toBeNull();
  });

  it('locks dragging in coarse navigation mode and frees it while organising', () => {
    function mountWithLayout(coarse: boolean, organising: boolean) {
      TestBed.resetTestingModule();
      TestBed.configureTestingModule({
        imports: [SidebarComponent, provideTranslocoTesting()],
        providers: [
          { provide: ManageActions, useValue: manageActions },
          provideRouter([]),
          provideHttpClient(),
          provideHttpClientTesting(),
          { provide: API_BASE_URL, useValue: 'https://api.test' },
          { provide: AuthService, useValue: { user: signal(account(null)) } },
          {
            provide: LayoutService,
            useValue: { isCoarse: signal(coarse), isNarrow: signal(false) },
          },
          { provide: ActionSheet, useValue: { open: jest.fn(() => of(undefined)) } },
        ],
      });
      const fixture = TestBed.createComponent(SidebarComponent);
      fixture.componentRef.setInput('tagTree', tree);
      fixture.componentRef.setInput('untagged', []);
      fixture.componentRef.setInput('totalUnread', 0);
      fixture.componentRef.setInput('selection', { kind: 'all', id: null, unread: true });
      fixture.componentRef.setInput('loading', false);
      fixture.componentRef.setInput('organising', organising);
      fixture.detectChanges();
      return fixture;
    }

    const nav = mountWithLayout(true, false);
    expect(nav.debugElement.query(By.directive(CdkDrag)).injector.get(CdkDrag).disabled).toBe(true);
    const org = mountWithLayout(true, true);
    expect(org.debugElement.query(By.directive(CdkDrag)).injector.get(CdkDrag).disabled).toBe(
      false,
    );
    expect(org.componentInstance.dragDelay()).toBe(0);
    const desktop = mountWithLayout(false, false);
    expect(desktop.debugElement.query(By.directive(CdkDrag)).injector.get(CdkDrag).disabled).toBe(
      false,
    );
    expect(desktop.componentInstance.dragDelay()).toEqual({ touch: 180, mouse: 0 });
  });

  it('desktop shows the trailing chevron, inline menu and popover', () => {
    const element = mount({ tagTree: tree }).nativeElement as HTMLElement;
    expect(element.querySelector('.tag .chevzone')).not.toBeNull();
    expect(element.querySelector('.handle')).toBeNull();
    expect(element.querySelector('.rowmenu .dots')).not.toBeNull();
  });

  describe('collapse button', () => {
    beforeEach(() => localStorage.clear());

    it('shows a collapse button on a wide layout', () => {
      const element = mount().nativeElement as HTMLElement;
      expect(element.querySelector('.collapse[aria-label="Hide sidebar"]')).not.toBeNull();
    });

    it('omits the collapse button on a narrow layout', () => {
      const element = mount({ narrow: true }).nativeElement as HTMLElement;
      expect(element.querySelector('.collapse')).toBeNull();
    });

    it('omits the collapse button while organising', () => {
      // Organise mode only holds on a coarse pointer; a fine pointer resets it.
      const element = mount({ organising: true, coarse: true }).nativeElement as HTMLElement;
      expect(element.querySelector('.collapse')).toBeNull();
    });

    it('hides the sidebar when the collapse button is clicked', () => {
      const fixture = mount();
      const element = fixture.nativeElement as HTMLElement;
      (element.querySelector('.collapse') as HTMLButtonElement).click();
      expect(TestBed.inject(SidebarVisibilityService).hidden()).toBe(true);
    });
  });
});
