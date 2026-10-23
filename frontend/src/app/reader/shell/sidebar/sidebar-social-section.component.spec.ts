import { signal } from '@angular/core';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { API_BASE_URL } from '../../../core/api';
import { ActionSheetAction } from '../../../shared/action-sheet/action-sheet.component';
import { ManageActions } from '../../feeds/manage/manage-actions.service';
import { LayoutService } from '../../layout.service';
import { SubscriptionDto } from '../../models';
import { Selection } from '../../query/query';
import { SocialNetworkName } from '../../../core/social-network';
import { SavedSearchesStore } from '../../state/saved-searches.store';
import { SocialSection, socialProfiles } from '../../state/social-sections';
import { makeSubscription } from '../../testing/subscription.factory';
import { SidebarRowActions } from './sidebar-row-actions.service';
import { SidebarSocialSectionComponent } from './sidebar-social-section.component';

const person = (id: number, title: string): SubscriptionDto =>
  makeSubscription({ id, title, social: { network: 'bluesky', accountId: null, kind: 'profile' } });

const feed = (
  id: number,
  kind: 'home' | 'list',
  over: Partial<SubscriptionDto> = {},
): SubscriptionDto =>
  makeSubscription({
    id,
    title: 'Feed',
    unreadCount: id,
    social: { network: 'bluesky', accountId: 3, kind },
    ...over,
  });

const ONE_ACCOUNT: SocialSection = {
  network: 'bluesky',
  networkAccounts: [
    { id: 3, network: 'bluesky', handle: '@alice.bsky.social', status: 'connected' },
  ],
  profiles: socialProfiles([], 0),
  unreadCount: 3,
  entryCount: 0,
  saved: null,
  showsHandles: false,
  accounts: [
    {
      accountId: 3,
      handle: '@alice.bsky.social',
      subscriptions: [
        feed(1, 'home', { title: 'Home@bsky' }),
        feed(2, 'list', { title: 'Writers' }),
      ],
    },
  ],
};

const expandProfiles = (network: SocialSection['network']): void =>
  localStorage.setItem(`sfr.${network}.profiles.collapsed`, 'false');

const ALL: Selection = { kind: 'all', id: null, unread: true };

const LONE_ACCOUNT_ACTIONS: ActionSheetAction[] = [
  { id: '3', label: 'Manage feeds…', disabled: false },
];
let socialActions = LONE_ACCOUNT_ACTIONS;
const INCLUDE_NETWORK_ACTION: ActionSheetAction = {
  id: 'savedSearches',
  label: 'Include in saved searches',
};
let networkAction = INCLUDE_NETWORK_ACTION;
const rowActions = {
  menuFor: signal<string | null>(null),
  toggleMenu: jest.fn(),
  closeMenu: jest.fn(),
  openFeedSheet: jest.fn(),
  openSocialSheet: jest.fn(),
  socialAccountActions: jest.fn(() => socialActions),
  manageSocialFeeds: jest.fn(),
  networkSavedSearchesAction: jest.fn(() => networkAction),
  toggleNetworkInSavedSearches: jest.fn(),
};

const savedSearchesStore = {
  includedNetworks: signal<ReadonlySet<SocialNetworkName>>(new Set()),
};

function mount(
  section: SocialSection,
  selection: Selection = ALL,
  coarse = false,
): ComponentFixture<SidebarSocialSectionComponent> {
  TestBed.resetTestingModule();
  TestBed.configureTestingModule({
    imports: [SidebarSocialSectionComponent, provideTranslocoTesting()],
    providers: [
      provideRouter([{ path: '**', children: [] }]),
      provideHttpClient(),
      provideHttpClientTesting(),
      { provide: API_BASE_URL, useValue: 'https://api.test' },
      { provide: LayoutService, useValue: { isCoarse: signal(coarse), isNarrow: signal(false) } },
      { provide: ManageActions, useValue: {} },
      { provide: SidebarRowActions, useValue: rowActions },
      { provide: SavedSearchesStore, useValue: savedSearchesStore },
    ],
  });
  const fixture = TestBed.createComponent(SidebarSocialSectionComponent);
  fixture.componentRef.setInput('section', section);
  fixture.componentRef.setInput('selection', selection);
  fixture.detectChanges();
  return fixture;
}

const element = (fixture: ComponentFixture<SidebarSocialSectionComponent>): HTMLElement =>
  fixture.nativeElement as HTMLElement;

describe('SidebarSocialSectionComponent', () => {
  beforeEach(() => {
    localStorage.clear();
    rowActions.menuFor.set(null);
    savedSearchesStore.includedNetworks.set(new Set());
    socialActions = LONE_ACCOUNT_ACTIONS;
    networkAction = INCLUDE_NETWORK_ACTION;
    jest.clearAllMocks();
  });
  afterEach(() => localStorage.clear());

  it('titles the section by its network and lists each feed by its display title', () => {
    const root = element(mount(ONE_ACCOUNT));

    expect(root.querySelector('.section-link')?.textContent).toContain('Bluesky');
    const rows = [...root.querySelectorAll('app-sidebar-feed-row')].map(
      (row) => row.textContent ?? '',
    );
    expect(rows[0]).toContain('Home@bsky');
    expect(rows[1]).toContain('Writers');
    expect(root.querySelector('.account-handle')).toBeNull();
  });

  it('keeps a renamed Home under the name it was given', () => {
    const renamed: SocialSection = {
      network: 'bluesky',
      unreadCount: 0,
      entryCount: 0,
      saved: null,
      showsHandles: false,
      networkAccounts: [],
      profiles: socialProfiles([], 0),
      accounts: [
        {
          accountId: 3,
          handle: null,
          subscriptions: [feed(1, 'home', { title: 'Main', customTitle: 'Main' })],
        },
      ],
    };

    expect(element(mount(renamed)).querySelector('app-sidebar-feed-row')?.textContent).toContain(
      'Main',
    );
  });

  it('heads each account with its handle when the network has several', () => {
    const twoAccounts: SocialSection = {
      network: 'bluesky',
      unreadCount: 0,
      entryCount: 0,
      saved: null,
      showsHandles: true,
      networkAccounts: [],
      profiles: socialProfiles([], 0),
      accounts: [
        {
          accountId: 3,
          handle: '@alice.bsky.social',
          subscriptions: [feed(1, 'home', { title: 'Home' })],
        },
        {
          accountId: 4,
          handle: '@work.bsky.social',
          subscriptions: [
            feed(2, 'home', {
              title: 'Home',
              social: { network: 'bluesky', accountId: 4, kind: 'home' },
            }),
          ],
        },
      ],
    };

    const handles = [...element(mount(twoAccounts)).querySelectorAll('.account-handle')].map(
      (handle) => handle.textContent?.trim(),
    );
    expect(handles).toEqual(['@alice.bsky.social', '@work.bsky.social']);
  });

  it("shows each feed's unread count", () => {
    const counts = [...element(mount(ONE_ACCOUNT)).querySelectorAll('.count')].map((count) =>
      count.textContent?.trim(),
    );

    expect(counts).toEqual(['1', '2']);
  });

  it('collapses and remembers it for its own network only', () => {
    const fixture = mount(ONE_ACCOUNT);

    (element(fixture).querySelector('.section-chevron') as HTMLButtonElement).click();
    fixture.detectChanges();

    expect(element(fixture).querySelectorAll('app-sidebar-feed-row').length).toBe(0);
    expect(localStorage.getItem('sfr.bluesky.collapsed')).toBe('true');
    expect(localStorage.getItem('sfr.mastodon.collapsed')).toBeNull();
    expect(element(mount(ONE_ACCOUNT)).querySelectorAll('app-sidebar-feed-row').length).toBe(0);
  });

  it('leaves out the heading of an account whose handle is unknown', () => {
    const unknownHandle: SocialSection = {
      network: 'bluesky',
      unreadCount: 0,
      entryCount: 0,
      saved: null,
      showsHandles: true,
      networkAccounts: [],
      profiles: socialProfiles([], 0),
      accounts: [
        { accountId: 3, handle: null, subscriptions: [feed(1, 'home')] },
        { accountId: 4, handle: '@work.bsky.social', subscriptions: [feed(2, 'home')] },
      ],
    };

    const handles = [...element(mount(unknownHandle)).querySelectorAll('.account-handle')].map(
      (handle) => handle.textContent?.trim(),
    );
    expect(handles).toEqual(['@work.bsky.social']);
  });

  it('marks a private feed only for saved searches, not for the surfaces it is out of by design', () => {
    const excluded = (id: number) =>
      feed(id, 'list', {
        includeInAllItems: false,
        includeInForYou: false,
        includeInSavedSearches: false,
      });
    const section: SocialSection = {
      network: 'bluesky',
      unreadCount: 0,
      entryCount: 0,
      saved: null,
      showsHandles: false,
      networkAccounts: [],
      profiles: socialProfiles([], 0),
      accounts: [{ accountId: 3, handle: null, subscriptions: [excluded(1)] }],
    };

    const marker = element(mount(section)).querySelector('.feed-exclusion-marker');
    expect(marker?.getAttribute('title')).toBe('Hidden from Saved searches');
  });

  it('lists the saved feed as a row of its own after the accounts', () => {
    const savedFeed = makeSubscription({
      id: 9,
      title: 'Saved from Bluesky',
      sourceFormat: 'saved',
      entryCount: 2,
      social: { network: 'bluesky', accountId: null, kind: 'saved' },
    });
    const root = element(mount({ ...ONE_ACCOUNT, saved: savedFeed }));

    const row = root.querySelector('[data-testid="sidebar-social-saved"]');
    expect(row?.textContent).toContain('Saved from Bluesky');
    expect(
      element(mount(ONE_ACCOUNT)).querySelector('[data-testid="sidebar-social-saved"]'),
    ).toBeNull();
  });

  const WITH_PROFILES: SocialSection = {
    ...ONE_ACCOUNT,
    profiles: socialProfiles(
      [
        { ...person(7, 'Amy'), unreadCount: 2 },
        { ...person(8, 'Zed'), unreadCount: 3 },
      ],
      5,
    ),
  };
  const feedsRow = (root: HTMLElement) =>
    root.querySelector('[data-testid="sidebar-social-feeds"]') as HTMLElement | null;

  it('shows the profile feeds as a collapsed Feeds row with their summed count', () => {
    const root = element(mount(WITH_PROFILES));

    expect(feedsRow(root)?.textContent).toContain('Feeds');
    expect(feedsRow(root)?.querySelector('app-tag-glyph')).not.toBeNull();
    expect(feedsRow(root)?.querySelector('.count')?.textContent?.trim()).toBe('5');
    expect(root.querySelectorAll('app-sidebar-feed-row').length).toBe(2);
  });

  it('lists the profiles under the row once its chevron opens it, and remembers that', () => {
    const fixture = mount(WITH_PROFILES);

    (feedsRow(element(fixture))?.querySelector('.chevzone') as HTMLButtonElement).click();
    fixture.detectChanges();

    const names = [
      ...element(fixture).querySelectorAll('.tagfeeds app-sidebar-feed-row .nav > .name'),
    ].map((name) => name.textContent);
    expect(names).toEqual(['Amy', 'Zed']);
    expect(localStorage.getItem('sfr.bluesky.profiles.collapsed')).toBe('false');
    expect(
      element(mount(WITH_PROFILES)).querySelectorAll('.tagfeeds app-sidebar-feed-row').length,
    ).toBe(2);
  });

  it("links the row to the network's combined profile list without expanding it", () => {
    const fixture = mount(WITH_PROFILES);
    const link = feedsRow(element(fixture))?.querySelector('a.nav') as HTMLAnchorElement;

    expect(link.getAttribute('href')).toContain('network=bluesky');
    expect(link.getAttribute('href')).toContain('feedKind=profile');
    link.click();
    fixture.detectChanges();
    expect(element(fixture).querySelector('.tagfeeds')).toBeNull();
  });

  it('marks the row, not the network title, active while the profile list is open', () => {
    const profiles: Selection = {
      kind: 'network',
      id: null,
      unread: true,
      network: 'bluesky',
      feedKind: 'profile',
    };
    const root = element(mount(WITH_PROFILES, profiles));

    expect(feedsRow(root)?.querySelector('a.nav.active')).not.toBeNull();
    expect(root.querySelector('.section-head.current')).toBeNull();
  });

  it('shows no Feeds row without profile feeds', () => {
    expect(feedsRow(element(mount(ONE_ACCOUNT)))).toBeNull();
  });

  it('shows a network that has only people, without an account heading', () => {
    const section: SocialSection = {
      network: 'mastodon',
      unreadCount: 0,
      entryCount: 0,
      saved: null,
      showsHandles: false,
      networkAccounts: [],
      accounts: [],
      profiles: socialProfiles([person(7, 'Amy')], 0),
    };

    expandProfiles('mastodon');
    const root = element(mount(section));

    expect(root.querySelector('.section-link')?.textContent).toContain('Mastodon');
    expect(root.querySelector('.account-handle')).toBeNull();
    expect(root.querySelectorAll('app-sidebar-feed-row').length).toBe(1);
  });

  it('keeps the exclusion marker on an excluded profile feed, which is ordinary RSS', () => {
    const excluded = { ...person(7, 'Amy'), includeInAllItems: false };
    const section: SocialSection = {
      network: 'bluesky',
      accounts: [],
      profiles: socialProfiles([excluded], 0),
      unreadCount: 0,
      entryCount: 0,
      saved: null,
      showsHandles: false,
      networkAccounts: [],
    };

    expandProfiles('bluesky');

    expect(element(mount(section)).querySelector('.feed-exclusion-marker')).not.toBeNull();
  });

  it("links the title to the network's combined view", () => {
    const link = element(mount(ONE_ACCOUNT)).querySelector('a.section-link') as HTMLAnchorElement;

    expect(link.getAttribute('href')).toContain('network=bluesky');
  });

  it('opens the view from the title without collapsing the section', () => {
    const fixture = mount(ONE_ACCOUNT);

    (element(fixture).querySelector('a.section-link') as HTMLAnchorElement).click();
    fixture.detectChanges();

    expect(element(fixture).querySelectorAll('app-sidebar-feed-row').length).toBe(2);
    expect(localStorage.getItem('sfr.bluesky.collapsed')).toBeNull();
  });

  it("marks the head row current while the network's view is open", () => {
    const bluesky: Selection = { kind: 'network', id: null, unread: true, network: 'bluesky' };
    const mastodon: Selection = { ...bluesky, network: 'mastodon' };

    expect(
      element(mount(ONE_ACCOUNT, bluesky)).querySelector('.section-head.current'),
    ).not.toBeNull();
    expect(element(mount(ONE_ACCOUNT, mastodon)).querySelector('.section-head.current')).toBeNull();
  });

  it('keeps the head neutral while organising, even with its view open', () => {
    const bluesky: Selection = { kind: 'network', id: null, unread: true, network: 'bluesky' };
    const fixture = mount(ONE_ACCOUNT, bluesky, true);
    fixture.componentRef.setInput('organising', true);
    fixture.detectChanges();

    expect(element(fixture).querySelector('.section-head.current')).toBeNull();
  });

  it("shows the network's unread count between the title and the chevron", () => {
    const head = element(mount(ONE_ACCOUNT)).querySelector('.section-head') as HTMLElement;

    expect(head.querySelector('.section-link .section-count')?.textContent?.trim()).toBe('3');
    expect(head.lastElementChild?.classList).toContain('section-chevron');
  });

  it('shows no count for a network with nothing unread', () => {
    const quiet: SocialSection = { ...ONE_ACCOUNT, unreadCount: 0 };

    expect(element(mount(quiet)).querySelector('.section-count')).toBeNull();
  });

  describe("the head's menu", () => {
    const itemsOf = (fixture: ComponentFixture<SidebarSocialSectionComponent>) => [
      ...element(fixture).querySelectorAll<HTMLButtonElement>(
        '[data-testid="sidebar-social-bluesky"] .pop button',
      ),
    ];

    it('opens Manage feeds for the network’s only account from the head’s menu', () => {
      const fixture = mount(ONE_ACCOUNT);
      rowActions.menuFor.set('social-bluesky');
      fixture.detectChanges();

      const [item] = itemsOf(fixture);
      expect(item.textContent?.trim()).toBe('Manage feeds…');
      item.click();

      expect(rowActions.socialAccountActions).toHaveBeenCalledWith(ONE_ACCOUNT);
      expect(rowActions.manageSocialFeeds).toHaveBeenCalledWith(ONE_ACCOUNT.networkAccounts[0]);
    });

    it('renders one item per account action, disabling the disabled ones', () => {
      const bob = {
        id: 9,
        network: 'bluesky' as const,
        handle: '@bob.bsky.social',
        status: 'needs_reconnect' as const,
      };
      socialActions = [
        { id: '3', label: 'Manage feeds of @alice.bsky.social', disabled: false },
        { id: '9', label: 'Reconnect @bob.bsky.social in settings', disabled: true },
      ];
      const fixture = mount({
        ...ONE_ACCOUNT,
        networkAccounts: [...ONE_ACCOUNT.networkAccounts, bob],
      });
      rowActions.menuFor.set('social-bluesky');
      fixture.detectChanges();

      expect(itemsOf(fixture).map((item) => [item.textContent?.trim(), item.disabled])).toEqual([
        ['Manage feeds of @alice.bsky.social', false],
        ['Reconnect @bob.bsky.social in settings', true],
        ['Include in saved searches', false],
      ]);
    });

    it('still offers the saved-search switch for a network without accounts', () => {
      socialActions = [];
      const fixture = mount({ ...ONE_ACCOUNT, networkAccounts: [] });
      rowActions.menuFor.set('social-bluesky');
      fixture.detectChanges();

      expect(itemsOf(fixture).map((item) => item.textContent?.trim())).toEqual([
        'Include in saved searches',
      ]);
    });

    it('labels the saved-search item for its network and switches that network on click', () => {
      const fixture = mount(ONE_ACCOUNT);
      rowActions.menuFor.set('social-bluesky');
      fixture.detectChanges();

      const item = itemsOf(fixture).at(-1) as HTMLButtonElement;
      expect(item.textContent?.trim()).toBe('Include in saved searches');
      item.click();

      expect(rowActions.networkSavedSearchesAction).toHaveBeenCalledWith(ONE_ACCOUNT);
      expect(rowActions.toggleNetworkInSavedSearches).toHaveBeenCalledWith(ONE_ACCOUNT);
    });

    it('shows the exclude label the row actions give an included network', () => {
      networkAction = { id: 'savedSearches', label: 'Exclude from saved searches' };
      const fixture = mount(ONE_ACCOUNT);
      rowActions.menuFor.set('social-bluesky');
      fixture.detectChanges();

      expect((itemsOf(fixture).at(-1) as HTMLElement).textContent?.trim()).toBe(
        'Exclude from saved searches',
      );
    });

    const profileSection = (subscription: SubscriptionDto): SocialSection => ({
      ...ONE_ACCOUNT,
      networkAccounts: [],
      accounts: [],
      profiles: socialProfiles([subscription], 0),
    });

    it('leaves a profile row of an unincluded network unmarked while its own flags are on', () => {
      expandProfiles('bluesky');
      const root = element(mount(profileSection(person(7, 'Amy'))));

      expect(root.querySelector('.favicon-slot.hidden-feed')).toBeNull();
      expect(root.querySelector('.feed-exclusion-marker')).toBeNull();
    });

    it('marks a profile row whose own saved-search switch is off, network included or not', () => {
      expandProfiles('bluesky');
      const fixture = mount(profileSection({ ...person(7, 'Amy'), includeInSavedSearches: false }));
      const title = () =>
        element(fixture).querySelector('.feed-exclusion-marker')?.getAttribute('title');

      expect(title()).toBe('Hidden from Saved searches');

      savedSearchesStore.includedNetworks.set(new Set(['bluesky']));
      fixture.detectChanges();

      expect(title()).toBe('Hidden from Saved searches');
    });

    it('opens the sheet on a coarse pointer', () => {
      const fixture = mount(ONE_ACCOUNT, ALL, true);

      (element(fixture).querySelector('.section-head .dots') as HTMLButtonElement).click();

      expect(rowActions.openSocialSheet).toHaveBeenCalledWith(ONE_ACCOUNT);
    });
  });

  describe('row avatars', () => {
    const avatarOf = (subscription: SubscriptionDto): string | null => {
      const section: SocialSection = {
        network: 'bluesky',
        accounts: [],
        profiles: socialProfiles([subscription], 0),
        unreadCount: 0,
        entryCount: 0,
        saved: null,
        showsHandles: false,
        networkAccounts: [],
      };
      expandProfiles('bluesky');
      const row = element(mount(section)).querySelector('app-sidebar-feed-row') as HTMLElement;
      return row.querySelector('img.avatar')?.getAttribute('src') ?? null;
    };

    it("shows a profile feed's image as a round avatar", () => {
      const amy = { ...person(7, 'Amy'), imageUrl: 'https://cdn.example/amy.jpg' };

      expect(avatarOf(amy)).toBe('https://cdn.example/amy.jpg');
    });

    it("shows a list's image as its avatar", () => {
      expect(avatarOf(feed(2, 'list', { imageUrl: 'https://cdn.example/list.jpg' }))).toBe(
        'https://cdn.example/list.jpg',
      );
    });

    it('keeps the favicon for a Home timeline', () => {
      expect(avatarOf(feed(1, 'home', { imageUrl: 'https://cdn.example/me.jpg' }))).toBeNull();
    });

    it("never shows an ordinary feed's channel logo as an avatar", () => {
      expect(
        avatarOf(makeSubscription({ id: 9, imageUrl: 'https://example.com/logo.png' })),
      ).toBeNull();
    });

    it('falls back to the favicon without an image', () => {
      const amy = { ...person(7, 'Amy'), faviconUrl: 'https://bsky.app/favicon.ico' };
      const section: SocialSection = {
        network: 'bluesky',
        accounts: [],
        profiles: socialProfiles([amy], 0),
        unreadCount: 0,
        entryCount: 0,
        saved: null,
        showsHandles: false,
        networkAccounts: [],
      };
      expandProfiles('bluesky');
      const row = element(mount(section)).querySelector('app-sidebar-feed-row') as HTMLElement;

      expect(row.querySelector('img.avatar')).toBeNull();
      expect(row.querySelector('img.favicon')?.getAttribute('src')).toBe(
        'https://bsky.app/favicon.ico',
      );
    });
  });
});
