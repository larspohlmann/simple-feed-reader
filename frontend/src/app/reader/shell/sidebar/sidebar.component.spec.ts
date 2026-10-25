import { ComponentFixture, TestBed } from '@angular/core/testing';
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
import { SidebarSavedSearchesComponent } from './sidebar-saved-searches.component';
import { TagNode, flattenTagTree } from '../../state/subscription-derivations';
import { TagsStore } from '../../state/tags.store';
import { SocialSection, socialProfiles } from '../../state/social-sections';
import { Selection } from '../../query/query';
import { FeedGroupName } from '../../../core/feed-group';
import { MoveFeedToTag, SavedSearchDto, SubscriptionDto, TagDto } from '../../models';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { LayoutService } from '../../layout.service';
import { SidebarVisibilityService } from '../sidebar-visibility.service';
import { ACTION_SHEET_DIVIDER } from '../../../shared/action-sheet/action-sheet.component';
import { ActionSheet } from '../../../shared/action-sheet/action-sheet.service';
import { of } from 'rxjs';
import { By } from '@angular/platform-browser';
import { ManageActions } from '../../feeds/manage/manage-actions.service';

const manageActions = {
  createTag: jest.fn(),
  editTag: jest.fn(),
  deleteTag: jest.fn(),
  editSubscription: jest.fn(),
  unsubscribe: jest.fn(),
  setIncludeInAllItems: jest.fn(),
  setIncludeInForYou: jest.fn(),
  setIncludeInSavedSearches: jest.fn(),
  moveFeedToTag: jest.fn(),
  reorderTags: jest.fn(),
  moveTag: jest.fn(),
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
  ai: { ready: false, model: null, capabilities: null },
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
  includeInSavedSearches: true,
  social: null,
});

const savedSearchesOf = (
  fixture: ComponentFixture<SidebarComponent>,
): SidebarSavedSearchesComponent =>
  fixture.debugElement.query(By.directive(SidebarSavedSearchesComponent)).componentInstance;

function inputDefaults() {
  return {
    tagTree: [] as TagNode[],
    untagged: [] as SubscriptionDto[],
    socialSections: [] as SocialSection[],
    totalUnread: 0,
    favoritesCount: 0,
    keptCount: 0,
    groupUnread: { tags: 0, feeds: 0 },
    selection: { kind: 'all', id: null, unread: true } as Selection,
    loading: false,
    searchLoading: false,
    organising: false,
    savedSearches: [] as SavedSearchDto[],
    activeSavedSearchId: null as number | null,
    mailEnabled: false,
    digestEnabled: true,
  };
}

function clickSectionChevron(
  fixture: ComponentFixture<SidebarComponent>,
  head: '.tags-head' | '.feeds-head',
): void {
  (fixture.nativeElement as HTMLElement)
    .querySelector<HTMLButtonElement>(`${head} .section-chevron`)!
    .click();
}

function mount(
  over: Partial<{
    tagTree: TagNode[];
    untagged: SubscriptionDto[];
    socialSections: SocialSection[];
    totalUnread: number;
    favoritesCount: number;
    keptCount: number;
    groupUnread: Readonly<Record<FeedGroupName, number>>;
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
      provideRouter([{ path: '**', children: [] }]),
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
  TestBed.inject(TagsStore).tags.set(flattenTagTree(over.tagTree ?? []).map((node) => node.tag));
  const fixture = TestBed.createComponent(SidebarComponent);
  for (const [name, fallback] of Object.entries(inputDefaults())) {
    fixture.componentRef.setInput(name, over[name as keyof typeof over] ?? fallback);
  }
  fixture.detectChanges();
  return fixture;
}

const tagNodeFixture: TagNode = {
  tag: { id: 40, name: 'Tech', color: null, icon: null, position: 0, parentId: null },
  subscriptions: [sub(1, 3)],
  children: [],
  unreadCount: 3,
  entryCount: 0,
};

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
    (element.querySelector('.tool[aria-label="Refresh"]') as HTMLButtonElement).click();
    (element.querySelector('.tool[aria-label="Add feed"]') as HTMLButtonElement).click();
    expect(refresh).toHaveBeenCalledTimes(1);
    expect(addFeed).toHaveBeenCalledTimes(1);
  });

  it('disables Refresh while refreshing and shows no progress bar of its own', () => {
    const fixture = mount();
    TestBed.inject(RefreshService).running.set(true);
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    expect(
      (element.querySelector('.tool[aria-label="Refresh"]') as HTMLButtonElement).disabled,
    ).toBe(true);
    // The refresh has exactly one bar and it belongs to the app bar. A second one
    // here was narrower than the first, sat directly under it on desktop, and drew
    // the same number twice (#721).
    expect(element.querySelector('.prog')).toBeNull();
  });

  it('renders tags with summed counts and reveals subs when expanded', () => {
    const node: TagNode = {
      tag: { id: 20, name: 'Tech', color: null, icon: null, position: 0, parentId: null },
      subscriptions: [sub(1, 3), sub(2, 6)],
      children: [],
      unreadCount: 9,
      entryCount: 0,
    };
    const fixture = mount({ tagTree: [node] });
    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('.tag')!.textContent).toContain('Tech');
    expect(element.querySelector('.tag')!.textContent).toContain('9');
    expect(element.querySelectorAll('.tagfeeds app-sidebar-feed-row').length).toBe(0);
    (element.querySelector('.tag .chevzone') as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(element.querySelectorAll('.tagfeeds app-sidebar-feed-row').length).toBe(2);
  });

  it('renders the tag icon (tinted with its colour) when set, else the colour dot', () => {
    const withIcon: TagNode = {
      tag: { id: 20, name: 'World', color: '#c08a3e', icon: 'public', position: 0, parentId: null },
      subscriptions: [],
      children: [],
      unreadCount: 0,
      entryCount: 0,
    };
    const withoutIcon: TagNode = {
      tag: { id: 21, name: 'Plain', color: null, icon: null, position: 1, parentId: null },
      subscriptions: [],
      children: [],
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
      tag: { id: 20, name: 'Tech', color: null, icon: null, position: 0, parentId: null },
      subscriptions: [],
      children: [],
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
    (buttons2[2] as HTMLButtonElement).click();
    expect(deleteTag).toHaveBeenCalledWith(node.tag);
  });

  describe('Create tag here', () => {
    const child: TagNode = {
      tag: { id: 21, name: 'Long reads', color: null, icon: null, position: 0, parentId: 20 },
      subscriptions: [],
      children: [],
      unreadCount: 0,
      entryCount: 0,
    };
    const root: TagNode = {
      tag: { id: 20, name: 'Reading', color: null, icon: null, position: 0, parentId: null },
      subscriptions: [],
      children: [child],
      unreadCount: 0,
      entryCount: 0,
    };

    function openRowMenu(tagName: string) {
      const fixture = mount({ tagTree: [root] });
      const element = fixture.nativeElement as HTMLElement;
      // Child rows render only once their root is expanded (`tagGroups`).
      element.querySelector<HTMLButtonElement>('.tag .chevzone')!.click();
      fixture.detectChanges();
      const row = [...element.querySelectorAll('.tag')].find((candidate) =>
        candidate.textContent?.includes(tagName),
      )!;
      row.querySelector<HTMLButtonElement>('.dots')!.click();
      fixture.detectChanges();
      const items = [...row.querySelectorAll<HTMLButtonElement>('.pop [role="menuitem"]')];
      return { fixture, element, items };
    }

    it('is not offered on a child tag', () => {
      const { items } = openRowMenu('Long reads');
      expect(items.map((item) => item.textContent?.trim())).not.toContain('Create tag here');
    });

    it('creates a tag under the root and closes the menu', () => {
      const { fixture, element, items } = openRowMenu('Reading');
      items.find((item) => item.textContent?.trim() === 'Create tag here')!.click();
      fixture.detectChanges();

      expect(manageActions.createTag).toHaveBeenCalledWith(20);
      expect(element.querySelector('.tag .pop')).toBeNull();
    });
  });

  it('creates a top-level tag from the Tags head menu', () => {
    const fixture = mount({ tagTree: [tagNodeFixture] });
    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('.tags-head .slot')).toBeNull();
    expect(element.querySelector('.feeds-head .dots')).toBeNull();

    element.querySelector<HTMLButtonElement>('.tags-head .dots')!.click();
    fixture.detectChanges();
    const items = element.querySelectorAll<HTMLButtonElement>('.tags-head .pop [role="menuitem"]');
    expect([...items].map((item) => item.textContent?.trim())).toEqual(['New tag']);
    items[0].click();
    fixture.detectChanges();

    expect(manageActions.createTag).toHaveBeenCalledWith();
    expect(element.querySelector('.tags-head .pop')).toBeNull();
  });

  it('closes an open row menu when the pointer goes down elsewhere', () => {
    const fixture = mount({
      tagTree: [
        {
          tag: { id: 20, name: 'Tech', color: null, icon: null, position: 0, parentId: null },
          subscriptions: [],
          children: [],
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
          tag: { id: 20, name: 'Tech', color: null, icon: null, position: 0, parentId: null },
          subscriptions: [shared],
          children: [],
          unreadCount: 0,
          entryCount: 0,
        },
        {
          tag: { id: 21, name: 'News', color: null, icon: null, position: 0, parentId: null },
          subscriptions: [shared],
          children: [],
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
      parentId: null,
    });
    const withTags = (subscription: SubscriptionDto, tags: TagDto[]): SubscriptionDto => ({
      ...subscription,
      tags,
    });

    function drop(
      item: SubscriptionDto,
      target: DropData,
      from: { source?: DropData; currentIndex?: number } = {},
    ): CdkDragDrop<DropData> {
      return {
        previousContainer: { data: from.source ?? { kind: 'untagged' } },
        container: { data: target },
        item: { data: item },
        currentIndex: from.currentIndex ?? 0,
      } as unknown as CdkDragDrop<DropData>;
    }

    const onTag = (id: number): DropData => ({ kind: 'tag', tag: tag(id) });

    function moveOf(event: CdkDragDrop<DropData>) {
      const fixture = mount();
      const spy = jest.fn();
      manageActions.moveFeedToTag.mockImplementation((sub: SubscriptionDto, move: MoveFeedToTag) =>
        spy({ sub, ...move }),
      );
      fixture.componentInstance.onDrop(event);
      return spy;
    }

    it('moves an untagged feed into a tag at the drop index', () => {
      const spy = moveOf(drop(sub(1), onTag(3), { source: { kind: 'untagged' }, currentIndex: 2 }));
      expect(spy).toHaveBeenCalledWith({ sub: sub(1), fromTagId: null, toTagId: 3, position: 2 });
    });

    it('moves a feed from its source tag to the target tag at the drop index', () => {
      const subscription = withTags(sub(1), [tag(3)]);
      const spy = moveOf(drop(subscription, onTag(7), { source: onTag(3), currentIndex: 1 }));
      expect(spy).toHaveBeenCalledWith({
        sub: subscription,
        fromTagId: 3,
        toTagId: 7,
        position: 1,
      });
    });

    it('moves a feed onto the untagged list at the drop index', () => {
      const subscription = withTags(sub(1), [tag(3)]);
      const spy = moveOf(
        drop(subscription, { kind: 'untagged' }, { source: onTag(3), currentIndex: 0 }),
      );
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
      manageActions.moveFeedToTag.mockImplementation((sub: SubscriptionDto, move: MoveFeedToTag) =>
        spy({ sub, ...move }),
      );
      fixture.componentInstance.onTagHeadDrop(drop(subscription, onTag(7), { source: onTag(3) }));
      expect(spy).toHaveBeenCalledWith({
        sub: subscription,
        fromTagId: 3,
        toTagId: 7,
        position: null,
      });
    });
  });

  describe('drag-and-drop reordering', () => {
    const tagNode = (
      id: number,
      subscriptions: SubscriptionDto[] = [],
      parentId: number | null = null,
    ): TagNode => ({
      tag: { id, name: `t${id}`, color: null, icon: null, position: 0, parentId },
      subscriptions,
      children: [],
      unreadCount: 0,
      entryCount: 0,
    });
    const parentNode = (id: number, children: TagNode[]): TagNode => ({ ...tagNode(id), children });

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

    it('moves a feed onto the tag when it is dropped on the tag header', () => {
      const fixture = mount({ tagTree: [tagNode(10)] });
      const spy = jest.fn();
      manageActions.moveFeedToTag.mockImplementation((sub: SubscriptionDto, move: MoveFeedToTag) =>
        spy({ sub, ...move }),
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

    describe('child tags (#1736)', () => {
      const tree = (): TagNode[] => [
        parentNode(10, [tagNode(11, [], 10), tagNode(12, [], 10)]),
        tagNode(20),
      ];
      const heads = (fixture: ReturnType<typeof mount>) =>
        [...(fixture.nativeElement as HTMLElement).querySelectorAll('.taghead')].map((head) => ({
          name: head.querySelector('.name')?.textContent?.trim(),
          child: head.closest('.tag-child') !== null,
        }));

      const blockKinds = (parent: Element) =>
        [...parent.children].map((block) =>
          ['taghead', 'tag-child', 'tagfeeds'].find((kind) => block.classList.contains(kind)),
        );

      it('nests children in a box, only while their parent is open', () => {
        const fixture = mount({ tagTree: tree() });
        expect(heads(fixture).map((head) => head.name)).toEqual(['t10', 't20']);

        fixture.componentInstance.toggle(10);
        fixture.detectChanges();

        expect(heads(fixture)).toEqual([
          { name: 't10', child: false },
          { name: 't11', child: true },
          { name: 't12', child: true },
          { name: 't20', child: false },
        ]);
      });

      it('nests an open root as a group: its head, a box per child, then its own feeds', () => {
        const fixture = mount({
          tagTree: [{ ...parentNode(10, [tagNode(11, [sub(2)], 10)]), subscriptions: [sub(1)] }],
        });
        fixture.componentInstance.toggle(10);
        fixture.componentInstance.toggle(11);
        fixture.detectChanges();

        const group = (fixture.nativeElement as HTMLElement).querySelector('.tags > .tag-group')!;
        expect(group.classList).toContain('open');
        expect(blockKinds(group)).toEqual(['taghead', 'tag-child', 'tagfeeds']);
        const box = group.querySelector('.tag-child')!;
        expect(blockKinds(box)).toEqual(['taghead', 'tagfeeds']);
        expect(box.querySelector('.taghead')!.closest('.tag-child')).toBe(box);
      });

      it('nests a grandchild box inside its open parent box, before the parent feeds', () => {
        const fixture = mount({
          tagTree: [
            parentNode(10, [
              { ...parentNode(11, [tagNode(12, [sub(3)], 11)]), subscriptions: [sub(2)] },
            ]),
          ],
        });
        fixture.componentInstance.toggle(10);
        fixture.componentInstance.toggle(11);
        fixture.componentInstance.toggle(12);
        fixture.detectChanges();

        const childBox = (fixture.nativeElement as HTMLElement).querySelector(
          '.tag-group > .tag-child',
        )!;
        expect(blockKinds(childBox)).toEqual(['taghead', 'tag-child', 'tagfeeds']);
        const grandchildBox = childBox.querySelector(':scope > .tag-child')!;
        expect(blockKinds(grandchildBox)).toEqual(['taghead', 'tagfeeds']);
        const nestedDropLists = (fixture.nativeElement as HTMLElement).querySelectorAll(
          '.cdk-drop-list .cdk-drop-list',
        );
        expect(nestedDropLists).toHaveLength(0);
      });

      it('shows grandchildren only while their parent is open', () => {
        const fixture = mount({
          tagTree: [parentNode(10, [parentNode(11, [tagNode(12, [], 11)])])],
        });
        fixture.componentInstance.toggle(10);
        fixture.detectChanges();
        expect(heads(fixture).map((head) => head.name)).toEqual(['t10', 't11']);

        fixture.componentInstance.toggle(11);
        fixture.detectChanges();
        expect(heads(fixture).map((head) => head.name)).toEqual(['t10', 't11', 't12']);
      });

      it('shows a closed child as a box with its head only', () => {
        const fixture = mount({ tagTree: [parentNode(10, [tagNode(11, [sub(2)], 10)])] });
        fixture.componentInstance.toggle(10);
        fixture.detectChanges();

        const box = (fixture.nativeElement as HTMLElement).querySelector('.tag-child')!;
        expect(blockKinds(box)).toEqual(['taghead']);
      });

      it('leaves a closed root a plain group with only its head', () => {
        const fixture = mount({ tagTree: tree() });

        const group = (fixture.nativeElement as HTMLElement).querySelector('.tags > .tag-group')!;
        expect(group.classList).not.toContain('open');
        expect(blockKinds(group)).toEqual(['taghead']);
      });

      it('keeps every drop list a sibling, never nested in another', () => {
        const fixture = mount({
          tagTree: [{ ...parentNode(10, [tagNode(11, [sub(2)], 10)]), subscriptions: [sub(1)] }],
        });
        fixture.componentInstance.toggle(10);
        fixture.componentInstance.toggle(11);
        fixture.detectChanges();
        const element = fixture.nativeElement as HTMLElement;

        expect(element.querySelectorAll('.tags .cdk-drop-list')).toHaveLength(4);
        expect(element.querySelectorAll('.cdk-drop-list .cdk-drop-list')).toHaveLength(0);
      });

      it("reorders feeds inside a child's own list", () => {
        const feeds = [sub(1), sub(2)];
        const fixture = mount({ tagTree: [parentNode(10, [tagNode(11, feeds, 10)])] });

        fixture.componentInstance.onDrop(
          reorder({ kind: 'tag', tag: tagNode(11, [], 10).tag }, 0, 1),
        );

        expect(manageActions.reorderTagFeeds).toHaveBeenCalledWith(11, [2, 1]);
      });
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
      'Exclude from saved searches',
      'Unsubscribe',
    ]);

    (
      element.querySelector('.feedrow .pop [role="menuitem"]:nth-of-type(2)') as HTMLButtonElement
    ).click();
    fixture.detectChanges();
    expect(toggleAllItems).toHaveBeenCalledWith(subscription);
    expect(element.querySelector('.feedrow .pop')).toBeNull();

    (element.querySelector('.feedrow .dots') as HTMLButtonElement).click();
    fixture.detectChanges();
    (
      element.querySelector('.feedrow .pop [role="menuitem"]:nth-of-type(3)') as HTMLButtonElement
    ).click();
    expect(toggleForYou).toHaveBeenCalledWith(subscription);
  });

  it('shows both exclusion toggles in the tagged feed row menu', () => {
    const subscription = sub(1, 0);
    const node: TagNode = {
      tag: { id: 20, name: 'Tech', color: null, icon: null, position: 0, parentId: null },
      subscriptions: [subscription],
      children: [],
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

    (element.querySelector('.tagfeeds .nav + .rowmenu .dots') as HTMLButtonElement).click();
    fixture.detectChanges();
    const labels = [
      ...element.querySelectorAll('.tagfeeds .nav + .rowmenu .pop [role="menuitem"]'),
    ].map((item) => item.textContent?.trim());
    expect(labels).toEqual([
      'Edit feed',
      'Exclude from All items',
      'Exclude from For You',
      'Exclude from saved searches',
      'Unsubscribe',
    ]);

    (
      element.querySelector(
        '.tagfeeds .nav + .rowmenu .pop [role="menuitem"]:nth-of-type(2)',
      ) as HTMLButtonElement
    ).click();
    expect(toggleAllItems).toHaveBeenCalledWith(subscription);

    (element.querySelector('.tagfeeds .nav + .rowmenu .dots') as HTMLButtonElement).click();
    fixture.detectChanges();
    (
      element.querySelector(
        '.tagfeeds .nav + .rowmenu .pop [role="menuitem"]:nth-of-type(3)',
      ) as HTMLButtonElement
    ).click();
    expect(toggleForYou).toHaveBeenCalledWith(subscription);
  });

  it('marks the selected subscription’s row host current', () => {
    const selection: Selection = { kind: 'subscription', id: 2, unread: true };
    const element = mount({ untagged: [sub(1, 0), sub(2, 0)], selection })
      .nativeElement as HTMLElement;
    const hosts = [...element.querySelectorAll('.feedlist app-sidebar-feed-row')];

    expect(hosts.map((host) => host.classList.contains('current'))).toEqual([false, true]);
  });

  it('greys a hidden feed’s favicon and badges it with the eye, keeping the count', () => {
    const fixture = mount({ untagged: [{ ...sub(2, 4), includeInForYou: false }] });
    const row = (fixture.nativeElement as HTMLElement).querySelector('.feedrow')!;
    const slot = row.querySelector('.favicon-slot')!;
    const marker = slot.querySelector('.feed-exclusion-marker')!;

    expect(slot.classList).toContain('hidden-feed');
    expect(slot.querySelector('app-favicon')).not.toBeNull();
    expect(marker.textContent).toContain('visibility_off');
    expect(marker.getAttribute('role')).toBe('img');
    expect(marker.getAttribute('title')).toBe('Hidden from For you');
    expect(marker.getAttribute('aria-label')).toBe('Hidden from For you');
    expect(row.querySelector('.count')?.textContent).toContain('4');
  });

  it('leaves a visible feed’s favicon unmarked', () => {
    const fixture = mount({ untagged: [sub(2, 4)] });
    const slot = (fixture.nativeElement as HTMLElement).querySelector('.feedrow .favicon-slot')!;

    expect(slot.classList).not.toContain('hidden-feed');
    expect(slot.querySelector('.feed-exclusion-marker')).toBeNull();
  });

  it('renders the exclusion marker when only includeInAllItems is false', () => {
    const excludedAllItems = { ...sub(2, 4), includeInAllItems: false };
    const fixture = mount({ untagged: [excludedAllItems] });
    const element = fixture.nativeElement as HTMLElement;
    const row = element.querySelector('.feedrow')!;
    expect(row.querySelector('.feed-exclusion-marker')).not.toBeNull();
    expect(row.querySelector('.count')?.textContent).toContain('4');
  });

  it('renders the exclusion marker when only includeInSavedSearches is false', () => {
    const fixture = mount({ untagged: [{ ...sub(2, 4), includeInSavedSearches: false }] });
    const marker = (fixture.nativeElement as HTMLElement).querySelector(
      '.feedrow .feed-exclusion-marker',
    );
    expect(marker?.getAttribute('title')).toBe('Hidden from Saved searches');
  });

  it('renders no exclusion marker when every switch is on', () => {
    const fixture = mount({ untagged: [sub(2, 4)] });
    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('.feedrow .feed-exclusion-marker')).toBeNull();
  });

  describe('dragging tags (#1765)', () => {
    const root = (id: number, position: number): TagDto => ({
      id,
      name: `t${id}`,
      color: null,
      icon: null,
      position,
      parentId: null,
    });
    const child = (id: number, parentId: number, position: number): TagDto => ({
      ...root(id, position),
      parentId,
    });
    const node = (tag: TagDto, children: TagNode[] = []): TagNode => ({
      tag,
      subscriptions: [],
      children,
      unreadCount: 0,
      entryCount: 0,
    });

    /** Headers 40 tall at index * 50 inside a list whose top is 0. */
    function mountTree(tree: TagNode[], expanded: number[] = []) {
      const fixture = mount({ tagTree: tree });
      fixture.componentInstance.expanded.set(new Set(expanded));
      fixture.detectChanges();
      const list = fixture.debugElement.query(By.css('.tags')).nativeElement as HTMLElement;
      const heads = fixture.debugElement.queryAll(By.css('.taghead'));
      jest
        .spyOn(list, 'getBoundingClientRect')
        .mockReturnValue({ top: 0, bottom: heads.length * 50 } as DOMRect);
      heads.forEach((head, index) =>
        jest
          .spyOn(head.nativeElement as HTMLElement, 'getBoundingClientRect')
          .mockReturnValue({ top: index * 50, bottom: index * 50 + 40, height: 40 } as DOMRect),
      );
      return fixture;
    }

    function dragTo(fixture: ComponentFixture<SidebarComponent>, dragged: TagDto, y: number): void {
      fixture.componentInstance.startTagDrag(dragged);
      fixture.componentInstance.moveTagDrag(y);
      fixture.detectChanges();
    }

    it('nests a tag dropped on the middle of another top-level tag and opens the parent', () => {
      const [first, second] = [root(1, 0), root(2, 1)];
      const fixture = mountTree([node(first), node(second)]);
      dragTo(fixture, second, 20);
      expect(fixture.debugElement.query(By.css('.taghead.drop-active'))).not.toBeNull();

      fixture.componentInstance.endTagDrag();

      expect(manageActions.moveTag).toHaveBeenCalledWith(second, {
        parentId: 1,
        order: [2],
        mark: { kind: 'nest', tagId: 1 },
      });
      expect(fixture.componentInstance.expanded().has(1)).toBe(true);
    });

    it('draws a line above the block a tag will land before', () => {
      const [first, second] = [root(1, 0), root(2, 1)];
      const fixture = mountTree([node(first), node(second)]);
      dragTo(fixture, second, 2);
      const groups = fixture.debugElement.queryAll(By.css('.tag-group'));
      expect(groups.map((group) => group.nativeElement.classList.contains('drop-before'))).toEqual([
        true,
        false,
      ]);
    });

    it('un-nests a child dropped on the top edge of a top-level tag', () => {
      const [first, second] = [root(1, 0), root(2, 1)];
      const nested = child(21, 2, 0);
      const fixture = mountTree([node(first), node(second, [node(nested)])], [2]);
      dragTo(fixture, nested, 2);
      fixture.componentInstance.endTagDrag();
      expect(manageActions.moveTag).toHaveBeenCalledWith(
        nested,
        expect.objectContaining({ parentId: null, order: [21, 1, 2] }),
      );
    });

    it('lands a tag as the first child of an open parent whose child header is queried later', () => {
      const [parent, other, dragged] = [root(1, 0), root(3, 1), root(2, 2)];
      const nested = child(11, 1, 0);
      const fixture = mountTree([node(parent, [node(nested)]), node(other), node(dragged)], [1]);
      dragTo(fixture, dragged, 38);
      fixture.componentInstance.endTagDrag();
      expect(manageActions.moveTag).toHaveBeenCalledWith(
        dragged,
        expect.objectContaining({ parentId: 1, order: [2, 11] }),
      );
    });

    it('writes nothing for a drop that changes nothing', () => {
      const [first, second] = [root(1, 0), root(2, 1)];
      const fixture = mountTree([node(first), node(second)]);
      dragTo(fixture, first, 2);
      fixture.componentInstance.endTagDrag();
      expect(manageActions.moveTag).not.toHaveBeenCalled();
    });

    it('keeps tag drags out of the header drop lists', () => {
      const fixture = mountTree([node(root(1, 0))]);
      expect(fixture.componentInstance.isFeedDrag({ data: root(2, 1) } as CdkDrag)).toBe(false);
    });
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

  describe('sidebar head', () => {
    it('puts the search field, refresh, add feed and hide sidebar into one card on a wide layout', () => {
      const element = mount({ narrow: false }).nativeElement as HTMLElement;
      const card = element.querySelector('.search-card')!;
      expect(card.querySelector('app-search-field.inset')).not.toBeNull();
      expect(
        [...card.querySelectorAll('.tool')].map((tool) => tool.getAttribute('aria-label')),
      ).toEqual(['Refresh', 'Add feed', 'Hide sidebar']);
      expect(element.querySelector('.drawer-head')).toBeNull();
    });

    it('titles the drawer Feeds and offers refresh, add feed and close on a narrow layout', () => {
      const element = mount({ narrow: true }).nativeElement as HTMLElement;
      const head = element.querySelector('.drawer-head')!;
      expect(head.querySelector('.drawer-title')!.textContent?.trim()).toBe('Feeds');
      expect(
        [...head.querySelectorAll('.tool')].map((tool) => tool.getAttribute('aria-label')),
      ).toEqual(['Refresh', 'Add feed', 'Close sidebar']);
      expect(element.querySelector('.search-card')).toBeNull();
    });

    it('emits closeDrawer from the close button', () => {
      const fixture = mount({ narrow: true });
      const closed = jest.fn();
      fixture.componentInstance.closeDrawer.subscribe(closed);

      (fixture.nativeElement as HTMLElement)
        .querySelector<HTMLButtonElement>('.close-drawer')!
        .click();

      expect(closed).toHaveBeenCalledTimes(1);
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

    it('navigates to the combined view instead of expanding', async () => {
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
      await fixture.whenStable();
      fixture.detectChanges();

      expect(TestBed.inject(Router).url).toBe('/searches/saved/all');
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

      expect(fixture.nativeElement.querySelector('.savedsearch-head')!.classList).toContain(
        'current',
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
        tag: { id: 30, name: 'Tech', color: null, icon: null, position: 0, parentId: null },
        subscriptions: [],
        children: [],
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

      savedSearchesOf(fixture).toggleSavedSearches();
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
      savedSearchesOf(fixture).toggleSavedSearches();
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
      savedSearchesOf(fixture).toggleSavedSearches();
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
      savedSearchesOf(fixture).toggleSavedSearches();
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
      savedSearchesOf(fixture).toggleSavedSearches();
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
      savedSearchesOf(fixture).toggleSavedSearches();
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
      savedSearchesOf(fixture).toggleSavedSearches();
      fixture.detectChanges();

      expect(fixture.nativeElement.querySelector('.digest-toggle')).toBeNull();
    });

    it('renders no mail icon when mail is on but the account digest is off', () => {
      const fixture = mount({ savedSearches: [climate], mailEnabled: true, digestEnabled: false });
      savedSearchesOf(fixture).toggleSavedSearches();
      fixture.detectChanges();

      expect(fixture.nativeElement.querySelector('.digest-toggle')).toBeNull();
    });

    it('renders a mail icon button per row when mail is enabled, muted only when not included', () => {
      const fixture = mount({ savedSearches: [climate, space], mailEnabled: true });
      savedSearchesOf(fixture).toggleSavedSearches();
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
      savedSearchesOf(fixture).toggleSavedSearches();
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
    const tagNode = tagNodeFixture;

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
      expect(head.querySelector('.section-chevron')!.getAttribute('aria-expanded')).toBe('true');
      expect(headChevronIcon(element)).toBe('expand_more');
      expect(head.querySelector('.chevzone')).toBeNull();
      expect(element.querySelector('.tags .taghead')).not.toBeNull();
    });

    it('collapses the list and points the chevron right when the chevron is clicked', () => {
      const fixture = mount({ tagTree: [tagNode] });
      const element = fixture.nativeElement as HTMLElement;
      (element.querySelector('.tags-head .section-chevron') as HTMLButtonElement).click();
      fixture.detectChanges();

      expect(
        element.querySelector('.tags-head .section-chevron')!.getAttribute('aria-expanded'),
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

    it('leaves the untag list mounted but empty when every feed is tagged, so the card gap stays single', () => {
      const fixture = mount({ tagTree: [tagNodeFixture], untagged: [] });
      const element = fixture.nativeElement as HTMLElement;

      expect(element.querySelector('.feeds-card')!.classList).not.toContain('card');
      expect(element.querySelector('.feeds-card > .feedlist')!.matches(':empty')).toBe(true);

      fixture.componentInstance.dragging.set(true);
      fixture.detectChanges();

      expect(element.querySelector('.feeds-card')!.classList).toContain('card');
      expect(element.querySelector('.feeds-card > .feedlist')!.matches(':empty')).toBe(false);
    });

    it('restores the collapsed state on a fresh mount (persisted)', () => {
      const first = mount({ tagTree: [tagNode] });
      clickSectionChevron(first, '.tags-head');

      TestBed.resetTestingModule();
      const element = mount({ tagTree: [tagNode] }).nativeElement as HTMLElement;
      expect(
        element.querySelector('.tags-head .section-chevron')!.getAttribute('aria-expanded'),
      ).toBe('false');
      expect(element.querySelector('.tags .taghead')).toBeNull();
    });
  });

  describe('section header links', () => {
    beforeEach(() => localStorage.clear());
    afterEach(() => localStorage.clear());

    it('links the Tags title to the combined tags list and shows its count', () => {
      const element = mount({ tagTree: [tagNodeFixture], groupUnread: { tags: 4, feeds: 0 } })
        .nativeElement as HTMLElement;
      const link = element.querySelector('.tags-head a.section-link') as HTMLAnchorElement;
      expect(link.getAttribute('href')).toContain('group=tags');
      expect(link.querySelector('.section-count')?.textContent?.trim()).toBe('4');
    });

    it('links the Feeds title to the combined feeds list and hides a zero count', () => {
      const element = mount({ untagged: [sub(1, 2)] }).nativeElement as HTMLElement;
      const link = element.querySelector('.feeds-head a.section-link') as HTMLAnchorElement;
      expect(link.getAttribute('href')).toContain('group=feeds');
      expect(link.querySelector('.section-count')).toBeNull();
    });

    it('opens the list from the title without collapsing the section', () => {
      const fixture = mount({ untagged: [sub(1, 2)] });
      const element = fixture.nativeElement as HTMLElement;
      (element.querySelector('.feeds-head a.section-link') as HTMLAnchorElement).click();
      fixture.detectChanges();
      expect(element.querySelector('.feedlist .feedrow')).not.toBeNull();
    });

    it("marks only the open group's head row current", () => {
      const selection: Selection = { kind: 'group', id: null, unread: true, group: 'feeds' };
      const element = mount({ tagTree: [tagNodeFixture], untagged: [sub(1, 2)], selection })
        .nativeElement as HTMLElement;
      expect(element.querySelector('.feeds-head.current')).not.toBeNull();
      expect(element.querySelector('.tags-head.current')).toBeNull();
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
      expect(head.querySelector('.section-chevron')!.getAttribute('aria-expanded')).toBe('true');
      expect(headChevronIcon(element)).toBe('expand_more');
      expect(element.querySelector('.feedlist .feedrow')).not.toBeNull();
    });

    it('collapses the untagged feeds and points the chevron right when the chevron is clicked', () => {
      const fixture = mount({ untagged: [sub(1, 2)] });
      const element = fixture.nativeElement as HTMLElement;
      (element.querySelector('.feeds-head .section-chevron') as HTMLButtonElement).click();
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
      clickSectionChevron(fixture, '.feeds-head');
      fixture.detectChanges();
      expect(element.querySelector('.feedlist .feedrow')).toBeNull();

      fixture.componentInstance.dragging.set(true);
      fixture.detectChanges();
      expect(element.querySelector('.feedlist .feedrow')).not.toBeNull();
    });

    it('restores the collapsed state on a fresh mount (persisted)', () => {
      const first = mount({ untagged: [sub(1, 2)] });
      clickSectionChevron(first, '.feeds-head');

      TestBed.resetTestingModule();
      const element = mount({ untagged: [sub(1, 2)] }).nativeElement as HTMLElement;
      expect(
        element.querySelector('.feeds-head .section-chevron')!.getAttribute('aria-expanded'),
      ).toBe('false');
      expect(element.querySelector('.feedlist .feedrow')).toBeNull();
    });
  });
});

describe('for-you row', () => {
  // AiAvailabilityService and RecommendationsService are faked with plain
  // signals — structural typing accepts them in place of the readonly ones
  // the real services expose.
  function mountWithAi(ready: boolean, running = false, forYouUnread = 0) {
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
          useValue: { running: signal(running) },
        },
      ],
    });
    const fixture = TestBed.createComponent(SidebarComponent);
    fixture.componentRef.setInput('tagTree', []);
    fixture.componentRef.setInput('untagged', []);
    fixture.componentRef.setInput('totalUnread', 0);
    fixture.componentRef.setInput('forYouUnread', forYouUnread);
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
  const tag: TagDto = { id: 1, name: 'News', color: null, icon: null, position: 0, parentId: null };
  const tree: TagNode[] = [
    { tag, subscriptions: [sub(5)], children: [], unreadCount: 3, entryCount: 0 },
  ];

  it('the Tags head carries a ⋯ that opens the Tags sheet while organising', () => {
    const fixture = mount({ coarse: true, organising: true, tagTree: tree, sheetChoice: 'new' });
    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('.feeds-head .dots')).toBeNull();

    element.querySelector<HTMLButtonElement>('.tags-head .dots')!.click();

    expect(TestBed.inject(ActionSheet).open).toHaveBeenCalledWith(
      expect.objectContaining({ title: 'Tags' }),
    );
    expect(manageActions.createTag).toHaveBeenCalledWith();
  });

  it('badges a hidden feed while organising too', () => {
    const element = mount({
      coarse: true,
      organising: true,
      untagged: [{ ...sub(9), includeInAllItems: false }],
    }).nativeElement as HTMLElement;

    expect(
      element.querySelector('.feedrow .favicon-slot.hidden-feed .feed-exclusion-marker'),
    ).not.toBeNull();
  });

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
    expect(element.querySelector('.tool')).toBeNull();
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
    expect(element.querySelector('.tool[aria-label="Refresh"]')).not.toBeNull();
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

  it('organise tag rows carry a handle, an inert body, ⋯ and a chevron that expands', () => {
    const fixture = mount({ coarse: true, organising: true, tagTree: tree });
    const element = fixture.nativeElement as HTMLElement;
    const row = element.querySelector('.tag')!;

    const ownClass = (child: Element) =>
      [...child.classList].find((name) => !name.startsWith('cdk-'));

    expect([...row.children].map(ownClass)).toEqual(['handle', 'rowbody', 'dots', 'chevzone']);
    expect(row.querySelector('.rowbody')!.tagName).toBe('SPAN');
    expect(row.querySelector('.nav.grow')).toBeNull();
    expect(row.querySelector('.chevzone')!.getAttribute('aria-expanded')).toBe('false');

    row.querySelector<HTMLButtonElement>('.chevzone')!.click();
    fixture.detectChanges();

    expect(element.querySelector('.tag .chevzone')!.getAttribute('aria-expanded')).toBe('true');
    expect(element.querySelector('.tagfeeds .handle')).not.toBeNull();
  });

  it('swaps the drawer head for Organise and Done, and Done ends organising', () => {
    const fixture = mount({ coarse: true, organising: true, narrow: true });
    const element = fixture.nativeElement as HTMLElement;

    expect(element.querySelector('.drawer-head')).toBeNull();
    expect(element.querySelector('.organise-head .drawer-title')!.textContent?.trim()).toBe(
      'Organise',
    );
    expect(element.querySelector('.organise-hint')).not.toBeNull();

    element.querySelector<HTMLButtonElement>('.organise-done')!.click();
    fixture.detectChanges();

    expect(fixture.componentInstance.organising()).toBe(false);
    expect(element.querySelector('.drawer-head')).not.toBeNull();
  });

  describe('organise layout', () => {
    afterEach(() => localStorage.clear());

    it('leaves out saved searches and keeps the tags open under a plain head', () => {
      const fixture = mount({
        coarse: true,
        tagTree: tree,
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
      clickSectionChevron(fixture, '.tags-head');
      fixture.componentRef.setInput('organising', true);
      fixture.detectChanges();
      const element = fixture.nativeElement as HTMLElement;

      expect(element.querySelector('app-sidebar-saved-searches')).toBeNull();
      expect(element.querySelector('.tags .taghead')).not.toBeNull();
      expect(element.querySelector('.tags-head .section-link')).toBeNull();
      expect(element.querySelector('.tags-head .section-chevron')).toBeNull();
      expect(element.querySelector('.tags-head .label')!.textContent?.trim()).toBe('Tags');
    });

    it('keeps the section heads neutral while organising, even for the open group', () => {
      const selection: Selection = { kind: 'group', id: null, unread: true, group: 'tags' };
      const element = mount({ coarse: true, organising: true, tagTree: tree, selection })
        .nativeElement as HTMLElement;

      expect(element.querySelector('.tags-head')).not.toBeNull();
      expect(element.querySelector('.section-head.current')).toBeNull();
    });
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
        { id: 'edit', label: 'Edit tag', symbol: 'edit' },
        { id: 'createHere', label: 'Create tag here', symbol: 'add' },
        { id: 'moveUp', label: 'Move up', symbol: 'arrow_upward', disabled: true },
        { id: 'moveDown', label: 'Move down', symbol: 'arrow_downward', disabled: true },
        ACTION_SHEET_DIVIDER,
        { id: 'delete', label: 'Delete tag', symbol: 'delete', danger: true },
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
        { id: 'allItems', label: 'Exclude from All items' },
        { id: 'forYou', label: 'Exclude from For You' },
        { id: 'savedSearches', label: 'Exclude from saved searches' },
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
      sheetChoice: 'allItems',
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

  it('routes the saved-searches sheet choice to setIncludeInSavedSearches', () => {
    const fixture = mount({
      coarse: true,
      organising: true,
      untagged: [sub(9)],
      sheetChoice: 'savedSearches',
    });
    const toggled = jest.fn();
    manageActions.setIncludeInSavedSearches.mockImplementation((sub: SubscriptionDto) =>
      toggled(sub),
    );
    fixture.nativeElement.querySelector('.feedrow .dots').click();
    expect(toggled).toHaveBeenCalledWith(expect.objectContaining({ id: 9 }));
    expect(manageActions.setIncludeInAllItems).not.toHaveBeenCalled();
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

  describe('social sections', () => {
    const section: SocialSection = {
      network: 'bluesky',
      networkAccounts: [],
      profiles: socialProfiles([], 0),
      unreadCount: 2,
      entryCount: 0,
      saved: null,
      showsHandles: false,
      accounts: [
        {
          accountId: 3,
          handle: '@alice.bsky.social',
          subscriptions: [
            {
              ...sub(9, 2),
              title: 'Home',
              social: { network: 'bluesky', accountId: 3, kind: 'home' },
            },
          ],
        },
      ],
    };
    const node: TagNode = {
      tag: { id: 20, name: 'Tech', color: null, icon: null, position: 0, parentId: null },
      subscriptions: [],
      children: [],
      unreadCount: 0,
      entryCount: 0,
    };

    beforeEach(() => localStorage.clear());
    afterEach(() => localStorage.clear());

    it('come last, after the tags and the untagged feeds', () => {
      const element = mount({ tagTree: [node], untagged: [sub(4)], socialSections: [section] })
        .nativeElement as HTMLElement;
      const social = element.querySelector('app-sidebar-social-section') as Element;
      const tags = element.querySelector('.tags-head') as Element;
      const feeds = element.querySelector('.feedlist') as Element;

      expect(tags.compareDocumentPosition(social) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
      expect(feeds.compareDocumentPosition(social) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });

    it('stay while organising, their rows managed through the sheet but never dragged', () => {
      const fixture = mount({
        organising: true,
        coarse: true,
        socialSections: [section],
        sheetChoice: 'edit',
      });
      const social = (fixture.nativeElement as HTMLElement).querySelector(
        'app-sidebar-social-section',
      ) as HTMLElement;
      const edited = jest.fn();
      manageActions.editSubscription.mockImplementation(edited);

      social.querySelector<HTMLButtonElement>('.feedrow .dots')!.click();

      expect(social.querySelector('.handle')).toBeNull();
      expect(edited).toHaveBeenCalledWith(section.accounts[0].subscriptions[0]);
    });
  });
});
