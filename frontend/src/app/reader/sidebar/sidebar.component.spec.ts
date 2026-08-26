import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { API_BASE_URL } from '../../core/api';
import { AuthService, CurrentUser } from '../../core/auth.service';
import { AiAvailabilityService } from '../../core/ai-availability.service';
import { RefreshService } from '../refresh.service';
import { RecommendationsService } from '../recommendations.service';
import { CdkDrag, CdkDragDrop } from '@angular/cdk/drag-drop';
import { DropData, SidebarComponent } from './sidebar.component';
import { TagNode } from '../subscriptions.store';
import { Selection } from '../query';
import { SavedSearchDto, SubscriptionDto, TagDto } from '../models';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { buildVersion } from '../../../environments/version';
import { VersionService } from '../../core/version.service';
import { LayoutService } from '../layout.service';
import { ActionSheet } from '../../shared/action-sheet/action-sheet.service';
import { of } from 'rxjs';
import { By } from '@angular/platform-browser';

const account = (trialEndsAt: string | null): CurrentUser => ({
  id: 1,
  email: 'me@x',
  roles: ['ROLE_USER'],
  status: 'active',
  createdAt: '2026-01-01T00:00:00Z',
  locale: 'en',
  trialEndsAt,
  preferences: { scrapeFallbackEnabled: false },
  ai: { ready: false, model: null },
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
  position: 0,
  tags: [],
  unreadCount: unread,
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
  }> = {},
) {
  TestBed.configureTestingModule({
    imports: [SidebarComponent, provideTranslocoTesting()],
    providers: [
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
  const f = TestBed.createComponent(SidebarComponent);
  f.componentRef.setInput('tagTree', over.tagTree ?? []);
  f.componentRef.setInput('untagged', over.untagged ?? []);
  f.componentRef.setInput('totalUnread', over.totalUnread ?? 0);
  f.componentRef.setInput('favoritesCount', over.favoritesCount ?? 0);
  f.componentRef.setInput('keptCount', over.keptCount ?? 0);
  f.componentRef.setInput('selection', over.selection ?? { kind: 'all', id: null, unread: true });
  f.componentRef.setInput('loading', false);
  f.componentRef.setInput('searchLoading', over.searchLoading ?? false);
  f.componentRef.setInput('organising', over.organising ?? false);
  f.componentRef.setInput('savedSearches', over.savedSearches ?? []);
  f.componentRef.setInput('activeSavedSearchId', over.activeSavedSearchId ?? null);
  f.detectChanges();
  return f;
}

describe('SidebarComponent', () => {
  it('shows the all-items total and marks it active', () => {
    const el = mount({ totalUnread: 24 }).nativeElement as HTMLElement;
    const all = el.querySelector('.nav.all')!;
    expect(all.textContent).toContain('24');
    expect(all.classList).toContain('active');
  });

  it('shows favourite and kept totals on their nav items, omitting a zero', () => {
    const el = mount({ favoritesCount: 5, keptCount: 0 }).nativeElement as HTMLElement;
    const navs = [...el.querySelectorAll('.nav')];
    const fav = navs.find((n) => n.textContent?.includes('Favorites'))!;
    const kept = navs.find((n) => n.textContent?.includes('Kept'))!;
    expect(fav.querySelector('.count')?.textContent).toContain('5');
    expect(kept.querySelector('.count')).toBeNull();
  });

  it('emits refresh and addFeed from the action buttons', () => {
    const f = mount();
    const el = f.nativeElement as HTMLElement;
    const refresh = jest.fn();
    const addFeed = jest.fn();
    f.componentInstance.refresh.subscribe(refresh);
    f.componentInstance.addFeed.subscribe(addFeed);
    (el.querySelector('.act[aria-label="Refresh"]') as HTMLButtonElement).click();
    (el.querySelector('.act[aria-label="Add feed"]') as HTMLButtonElement).click();
    expect(refresh).toHaveBeenCalledTimes(1);
    expect(addFeed).toHaveBeenCalledTimes(1);
  });

  it('disables Refresh and shows a progress bar while refreshing', () => {
    const f = mount();
    TestBed.inject(RefreshService).running.set(true);
    f.detectChanges();
    const el = f.nativeElement as HTMLElement;
    expect((el.querySelector('.act[aria-label="Refresh"]') as HTMLButtonElement).disabled).toBe(
      true,
    );
    expect(el.querySelector('.prog')).not.toBeNull();
  });

  it('renders tags with summed counts and reveals subs when expanded', () => {
    const node: TagNode = {
      tag: { id: 20, name: 'Tech', color: null, icon: null, position: 0 },
      subscriptions: [sub(1, 3), sub(2, 6)],
      unreadCount: 9,
    };
    const f = mount({ tagTree: [node] });
    const el = f.nativeElement as HTMLElement;
    expect(el.querySelector('.tag')!.textContent).toContain('Tech');
    expect(el.querySelector('.tag')!.textContent).toContain('9');
    expect(el.querySelectorAll('.tag-sub').length).toBe(0);
    (el.querySelector('.tag .chevzone') as HTMLButtonElement).click();
    f.detectChanges();
    expect(el.querySelectorAll('.tag-sub').length).toBe(2);
  });

  it('renders the tag icon (tinted with its colour) when set, else the colour dot', () => {
    const withIcon: TagNode = {
      tag: { id: 20, name: 'World', color: '#c08a3e', icon: 'public', position: 0 },
      subscriptions: [],
      unreadCount: 0,
    };
    const withoutIcon: TagNode = {
      tag: { id: 21, name: 'Plain', color: null, icon: null, position: 1 },
      subscriptions: [],
      unreadCount: 0,
    };
    const f = mount({ tagTree: [withIcon, withoutIcon] });
    const leads = (f.nativeElement as HTMLElement).querySelectorAll('.tag .lead');

    const icon = leads[0].querySelector('.material-symbols-outlined') as HTMLElement;
    expect(icon.textContent).toBe('public');
    expect(leads[0].querySelector('.dot')).toBeNull();
    // The colour tints the icon rather than a dot (jsdom normalises the hex).
    expect((leads[0].querySelector('app-icon') as HTMLElement).style.color).toBeTruthy();

    expect(leads[1].querySelector('.material-symbols-outlined')).toBeNull();
    expect(leads[1].querySelector('.dot')).not.toBeNull();
  });

  it('emits editTag / deleteTag when a tag row menu action is used', () => {
    const node: TagNode = {
      tag: { id: 20, name: 'Tech', color: null, icon: null, position: 0 },
      subscriptions: [],
      unreadCount: 0,
    };
    const f = mount({ tagTree: [node] });
    const el = f.nativeElement as HTMLElement;
    const editTag = jest.fn();
    const deleteTag = jest.fn();
    f.componentInstance.editTag.subscribe(editTag);
    f.componentInstance.deleteTag.subscribe(deleteTag);

    (el.querySelector('.tag .dots') as HTMLButtonElement).click();
    f.detectChanges();
    const buttons = el.querySelectorAll('.tag .pop [role="menuitem"]');
    (buttons[0] as HTMLButtonElement).click();
    f.detectChanges();
    expect(editTag).toHaveBeenCalledWith(node.tag);
    expect(el.querySelector('.tag .pop')).toBeNull();

    (el.querySelector('.tag .dots') as HTMLButtonElement).click();
    f.detectChanges();
    const buttons2 = el.querySelectorAll('.tag .pop [role="menuitem"]');
    (buttons2[1] as HTMLButtonElement).click();
    expect(deleteTag).toHaveBeenCalledWith(node.tag);
  });

  it('closes an open row menu when the pointer goes down elsewhere', () => {
    const f = mount({
      tagTree: [
        {
          tag: { id: 20, name: 'Tech', color: null, icon: null, position: 0 },
          subscriptions: [],
          unreadCount: 0,
        },
      ],
    });
    const el = f.nativeElement as HTMLElement;
    (el.querySelector('.tag .dots') as HTMLButtonElement).click();
    f.detectChanges();
    expect(el.querySelector('.tag .pop')).not.toBeNull();

    document.body.dispatchEvent(new Event('pointerdown', { bubbles: true }));
    f.detectChanges();

    expect(el.querySelector('.tag .pop')).toBeNull();
  });

  it('opens only one menu when the same feed appears under two expanded tags', () => {
    const shared = sub(1, 0);
    const f = mount({
      tagTree: [
        {
          tag: { id: 20, name: 'Tech', color: null, icon: null, position: 0 },
          subscriptions: [shared],
          unreadCount: 0,
        },
        {
          tag: { id: 21, name: 'News', color: null, icon: null, position: 0 },
          subscriptions: [shared],
          unreadCount: 0,
        },
      ],
    });
    const el = f.nativeElement as HTMLElement;
    el.querySelectorAll<HTMLButtonElement>('.tag .chevzone').forEach((b) => b.click());
    f.detectChanges();

    const dots = el.querySelectorAll<HTMLButtonElement>('.feedrow .dots');
    expect(dots.length).toBe(2); // the feed is rendered under both tags
    dots[0].click();
    f.detectChanges();

    // Distinct per-(tag,feed) keys mean only the clicked row's menu opens.
    expect(el.querySelectorAll('.pop').length).toBe(1);
  });

  describe('drag-and-drop retagging', () => {
    const tag = (id: number): TagDto => ({
      id,
      name: `t${id}`,
      color: null,
      icon: null,
      position: 0,
    });
    const withTags = (s: SubscriptionDto, tags: TagDto[]): SubscriptionDto => ({ ...s, tags });

    function drop(
      item: SubscriptionDto,
      target: DropData,
      sameContainer = false,
    ): CdkDragDrop<DropData> {
      const container = { data: target };
      const previousContainer = sameContainer
        ? container
        : { data: { kind: 'untagged' } as DropData };
      return {
        previousContainer,
        container,
        item: { data: item },
      } as unknown as CdkDragDrop<DropData>;
    }

    function retagOf(ev: CdkDragDrop<DropData>) {
      const f = mount();
      const spy = jest.fn();
      f.componentInstance.retag.subscribe(spy);
      f.componentInstance.onDrop(ev);
      return spy;
    }

    it('assigns the tag when an untagged feed is dropped on it', () => {
      const spy = retagOf(drop(sub(1), { kind: 'tag', tag: tag(3) }));
      expect(spy).toHaveBeenCalledWith({ sub: sub(1), tagIds: [3] });
    });

    it('adds the tag to a feed that already has other tags', () => {
      const s = withTags(sub(1), [tag(3)]);
      const spy = retagOf(drop(s, { kind: 'tag', tag: tag(7) }));
      expect(spy).toHaveBeenCalledWith({ sub: s, tagIds: [3, 7] });
    });

    it('does nothing when the feed already has the target tag', () => {
      const s = withTags(sub(1), [tag(3)]);
      const spy = retagOf(drop(s, { kind: 'tag', tag: tag(3) }));
      expect(spy).not.toHaveBeenCalled();
    });

    it('clears all tags when a tagged feed is dropped on Feeds', () => {
      const s = withTags(sub(1), [tag(3), tag(7)]);
      const spy = retagOf(drop(s, { kind: 'untagged' }));
      expect(spy).toHaveBeenCalledWith({ sub: s, tagIds: [] });
    });

    it('does nothing when dropped back on its own container', () => {
      const spy = retagOf(drop(sub(1), { kind: 'untagged' }, true));
      expect(spy).not.toHaveBeenCalled();
    });

    it('does nothing when an already-untagged feed is dropped on Feeds', () => {
      const spy = retagOf(drop(sub(1), { kind: 'untagged' }));
      expect(spy).not.toHaveBeenCalled();
    });
  });

  describe('drag-and-drop reordering', () => {
    const tagNode = (id: number, subs: SubscriptionDto[] = []): TagNode => ({
      tag: { id, name: `t${id}`, color: null, icon: null, position: 0 },
      subscriptions: subs,
      unreadCount: 0,
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

    it('emits reorderTags when a tag is dropped on another tag header', () => {
      const f = mount({ tagTree: [tagNode(10), tagNode(20), tagNode(30)] });
      const spy = jest.fn();
      f.componentInstance.reorderTags.subscribe(spy);
      // Drop the last tag (30) onto the first tag's header → 30 moves to front.
      f.componentInstance.onTagHeadDrop(
        tagHeadDrop(tagNode(30).tag, { kind: 'tag', tag: tagNode(10).tag }),
      );
      expect(spy).toHaveBeenCalledWith([30, 10, 20]);
    });

    it('does not emit when a tag is dropped back on its own header', () => {
      const f = mount({ tagTree: [tagNode(10), tagNode(20)] });
      const spy = jest.fn();
      f.componentInstance.reorderTags.subscribe(spy);
      f.componentInstance.onTagHeadDrop(
        tagHeadDrop(tagNode(10).tag, { kind: 'tag', tag: tagNode(10).tag }),
      );
      expect(spy).not.toHaveBeenCalled();
    });

    it('assigns the tag when a feed is dropped on the tag header', () => {
      const f = mount({ tagTree: [tagNode(10)] });
      const spy = jest.fn();
      f.componentInstance.retag.subscribe(spy);
      const s = sub(1);
      f.componentInstance.onTagHeadDrop({
        previousContainer: { data: { kind: 'untagged' } },
        container: { data: { kind: 'tag', tag: tagNode(10).tag } },
        item: { data: s },
      } as unknown as CdkDragDrop<DropData>);
      expect(spy).toHaveBeenCalledWith({ sub: s, tagIds: [10] });
    });

    it('emits reorderTagFeeds when a feed is reordered within its tag', () => {
      const feeds = [sub(1), sub(2), sub(3)];
      const f = mount({ tagTree: [tagNode(10, feeds)] });
      const spy = jest.fn();
      f.componentInstance.reorderTagFeeds.subscribe(spy);
      // Within tag 10, move feed at index 0 to index 2.
      f.componentInstance.onDrop(reorder({ kind: 'tag', tag: tagNode(10).tag }, 0, 2));
      expect(spy).toHaveBeenCalledWith({ tagId: 10, subscriptionIds: [2, 3, 1] });
    });

    it('emits reorderUntagged when an untagged feed is reordered', () => {
      const f = mount({ untagged: [sub(1), sub(2), sub(3)] });
      const spy = jest.fn();
      f.componentInstance.reorderUntagged.subscribe(spy);
      f.componentInstance.onDrop(reorder({ kind: 'untagged' }, 2, 0));
      expect(spy).toHaveBeenCalledWith([3, 1, 2]);
    });

    it('does not emit when an item is dropped back at its own index', () => {
      const f = mount({ untagged: [sub(1), sub(2)] });
      const spy = jest.fn();
      f.componentInstance.reorderUntagged.subscribe(spy);
      f.componentInstance.onDrop(reorder({ kind: 'untagged' }, 1, 1));
      expect(spy).not.toHaveBeenCalled();
    });
  });

  it('emits editFeed / unsubscribe for an untagged feed row', () => {
    const s = sub(1, 0);
    const f = mount({ untagged: [s] });
    const el = f.nativeElement as HTMLElement;
    const editFeed = jest.fn();
    const unsub = jest.fn();
    f.componentInstance.editFeed.subscribe(editFeed);
    f.componentInstance.unsubscribe.subscribe(unsub);

    (el.querySelector('.feedrow .dots') as HTMLButtonElement).click();
    f.detectChanges();
    const buttons = el.querySelectorAll('.feedrow .pop [role="menuitem"]');
    (buttons[0] as HTMLButtonElement).click();
    expect(editFeed).toHaveBeenCalledWith(s);

    (el.querySelector('.feedrow .dots') as HTMLButtonElement).click();
    f.detectChanges();
    const buttons2 = el.querySelectorAll('.feedrow .pop [role="menuitem"]');
    (buttons2[1] as HTMLButtonElement).click();
    expect(unsub).toHaveBeenCalledWith(s);
  });

  describe('search field', () => {
    it('renders on a wide screen', () => {
      const f = mount({ narrow: false });
      expect(f.nativeElement.querySelector('app-search-field')).toBeTruthy();
    });

    it('is absent on a narrow screen, where the mobile header owns search', () => {
      const f = mount({ narrow: true });
      expect(f.nativeElement.querySelector('app-search-field')).toBeNull();
    });

    it('forwards the settled term as the search output', () => {
      const f = mount({ narrow: false });
      const search = jest.fn();
      f.componentInstance.search.subscribe(search);

      const searchField = f.debugElement.query(
        (de) => de.name === 'app-search-field',
      )?.componentInstance;
      searchField.search.emit('cats');

      expect(search).toHaveBeenCalledWith('cats');
    });

    it('forwards searchLoading to the field, distinct from the subscriptions loading input', () => {
      const f = mount({ narrow: false, searchLoading: true });

      const searchField = f.debugElement.query(
        (de) => de.name === 'app-search-field',
      )?.componentInstance;

      expect(searchField.loading()).toBe(true);
    });
  });

  it('shows the running build as a link into settings', () => {
    const version = (mount().nativeElement as HTMLElement).querySelector('.version');

    expect(version?.textContent?.trim()).toBe(buildVersion.version);
    expect(version?.getAttribute('href')).toBe('/settings');
  });

  it('shows an update badge linking to the release notes when an update is available', () => {
    const f = mount();
    const versions = TestBed.inject(VersionService);
    versions.latest.set({ version: 'v9.9.9', notesUrl: 'https://github.test/releases/tag/v9.9.9' });
    versions.updateAvailable.set(true);
    f.detectChanges();

    const badge = (f.nativeElement as HTMLElement).querySelector('.update-badge');
    expect(badge).not.toBeNull();
    expect(badge?.textContent).toContain('v9.9.9');
    expect(badge?.getAttribute('href')).toBe('https://github.test/releases/tag/v9.9.9');
    expect(badge?.getAttribute('target')).toBe('_blank');
    expect(badge?.getAttribute('rel')).toBe('noopener noreferrer');
  });

  it('shows no update badge when the running build is current', () => {
    const f = mount();
    const versions = TestBed.inject(VersionService);
    versions.updateAvailable.set(false);
    versions.latest.set(null);
    f.detectChanges();

    expect((f.nativeElement as HTMLElement).querySelector('.update-badge')).toBeNull();
  });

  it('shows the trial countdown when a trial is active', () => {
    const f = mount({ user: account(inDays(5)) });
    const el = f.nativeElement.querySelector('.trial');
    expect(el?.textContent).toContain('5');
  });

  it('hides the trial countdown when there is no trial', () => {
    const f = mount({ user: account(null) });
    expect(f.nativeElement.querySelector('.trial')).toBeNull();
  });

  it('hides the trial countdown when the trial is already past', () => {
    const f = mount({ user: account(inDays(-1)) });
    expect(f.nativeElement.querySelector('.trial')).toBeNull();
  });

  describe('saved searches', () => {
    it('renders no saved-searches section when the list is empty', () => {
      const f = mount({ savedSearches: [] });
      expect(f.nativeElement.textContent).not.toContain('Saved searches');
    });

    it('renders collapsed by default, showing the header with the summed unread count', () => {
      const f = mount({
        savedSearches: [
          { id: 1, term: 'climate', wholeWord: false, position: 0, unreadCount: 3 },
          { id: 2, term: 'space', wholeWord: false, position: 1, unreadCount: 4 },
        ],
      });
      const text = f.nativeElement.textContent;
      expect(text).toContain('Saved searches');
      expect(text).not.toContain('climate');
      expect(f.nativeElement.querySelectorAll('.savedsearch-item').length).toBe(0);
      const head = f.nativeElement.querySelector('.savedsearch-head')!;
      expect(head.querySelector('.count')?.textContent).toContain('7');
    });

    it('expands on click, revealing the term rows while keeping the summed count', () => {
      const f = mount({
        savedSearches: [
          { id: 1, term: 'climate', wholeWord: false, position: 0, unreadCount: 3 },
          { id: 2, term: 'space', wholeWord: false, position: 1, unreadCount: 4 },
        ],
      });
      const head: HTMLElement = f.nativeElement.querySelector('.savedsearch-head');
      const toggle: HTMLButtonElement = head.querySelector('.savedsearch-toggle')!;
      expect(toggle.getAttribute('aria-expanded')).toBe('false');
      toggle.click();
      f.detectChanges();

      const text = f.nativeElement.textContent;
      expect(text).toContain('climate');
      expect(text).toContain('space');
      // The header keeps its summed unread count when expanded, the same way
      // a tag row keeps its own count — it does not disappear like the old
      // Task-12 behaviour.
      expect(head.querySelector('.count')?.textContent).toContain('7');
      expect(f.nativeElement.querySelectorAll('.savedsearch-item').length).toBe(2);
      // Activating the title itself must announce the new state to a screen
      // reader, not only the trailing chevron button (#581 follow-up).
      expect(toggle.getAttribute('aria-expanded')).toBe('true');
    });

    it('also expands on a click of its trailing chevron button', () => {
      const f = mount({
        savedSearches: [{ id: 1, term: 'climate', wholeWord: false, position: 0, unreadCount: 3 }],
      });
      const chevron: HTMLButtonElement = f.nativeElement.querySelector(
        '.savedsearch-head .chevzone',
      );
      chevron.click();
      f.detectChanges();

      expect(f.nativeElement.textContent).toContain('climate');
    });

    it('places the chevron in the same right-edge column as a tag chevron', () => {
      const node: TagNode = {
        tag: { id: 30, name: 'Tech', color: null, icon: null, position: 0 },
        subscriptions: [],
        unreadCount: 0,
      };
      const f = mount({
        tagTree: [node],
        savedSearches: [{ id: 1, term: 'climate', wholeWord: false, position: 0, unreadCount: 3 }],
      });
      const savedChev: HTMLElement = f.nativeElement.querySelector('.savedsearch-head .chevzone');
      const tagChev: HTMLElement = f.nativeElement.querySelector('.taghead .chevzone');
      expect(savedChev.className).toBe(tagChev.className);
    });

    // The header chevron follows the same convention as the Tags and Feeds
    // section chevrons: it points down (`expand_more`) when the list is open
    // and right (`chevron_right`) when it is collapsed — never up.
    it('points the header chevron down when expanded and right when collapsed', () => {
      const f = mount({
        savedSearches: [{ id: 1, term: 'climate', wholeWord: false, position: 0, unreadCount: 3 }],
      });
      const chevronIcon = (): string | null =>
        f.nativeElement.querySelector('.savedsearch-head .chevzone .material-symbols-outlined')
          ?.textContent ?? null;

      expect(chevronIcon()).toBe('chevron_right');

      f.componentInstance.toggleSavedSearches();
      f.detectChanges();

      expect(chevronIcon()).toBe('expand_more');
    });

    it('shows a compact "W" pill on a whole-word row and none on a plain row', () => {
      const f = mount({
        savedSearches: [
          { id: 1, term: 'climate', wholeWord: true, position: 0, unreadCount: 0 },
          { id: 2, term: 'space', wholeWord: false, position: 1, unreadCount: 0 },
        ],
      });
      f.componentInstance.toggleSavedSearches();
      f.detectChanges();

      const items = [...f.nativeElement.querySelectorAll('.savedsearch-item')];
      const wholeWordRow = items.find((item) => item.textContent?.includes('climate'))!;
      const plainRow = items.find((item) => item.textContent?.includes('space'))!;

      const badge = wholeWordRow.querySelector('.whole-word-badge')!;
      expect(badge.textContent?.trim()).toBe('W');
      expect(wholeWordRow.querySelector('.sr-only')?.textContent).toContain('Whole words');
      expect(plainRow.querySelector('.whole-word-badge')).toBeNull();
    });

    // The active row is decided by id, handed down by the shell. The sidebar
    // does NOT re-encode a term to string-match it against the selection: that
    // was a second, subtly different identity rule, and it disagreed with the
    // shell's whenever the whole-word signal was a tab or a no-break space.
    it('marks the row the shell names active, and only that one', () => {
      const f = mount({
        savedSearches: [
          { id: 1, term: 'climate', wholeWord: true, position: 0, unreadCount: 0 },
          { id: 2, term: 'space', wholeWord: false, position: 1, unreadCount: 0 },
        ],
        activeSavedSearchId: 1,
      });
      f.componentInstance.toggleSavedSearches();
      f.detectChanges();

      const items = [...f.nativeElement.querySelectorAll('.savedsearch-item')];
      const active = items.filter((item) => item.classList.contains('active'));
      expect(active).toHaveLength(1);
      expect(active[0].textContent).toContain('climate');
    });

    it('marks no row active when the shell names none', () => {
      const f = mount({
        savedSearches: [{ id: 1, term: 'climate', wholeWord: true, position: 0, unreadCount: 0 }],
      });
      f.componentInstance.toggleSavedSearches();
      f.detectChanges();

      expect(f.nativeElement.querySelector('.savedsearch-item.active')).toBeNull();
    });

    // RouterLink re-resolves an href whenever its queryParams object changes
    // identity, and savedSearchParams cannot use selectionQueryParams' cache
    // (an unbounded `q` must not grow it). Resolving the params once per list
    // change is what keeps a zone-based change-detection pass off that path.
    it('keeps each row link params object stable across change detection', () => {
      const f = mount({
        savedSearches: [{ id: 1, term: 'climate', wholeWord: true, position: 0, unreadCount: 0 }],
      });
      f.componentInstance.toggleSavedSearches();
      f.detectChanges();

      const before = f.componentInstance['savedSearchLinks']()[0].params;
      f.detectChanges();
      f.detectChanges();

      expect(f.componentInstance['savedSearchLinks']()[0].params).toBe(before);
    });
  });

  describe('collapsible tag list', () => {
    const tagNode: TagNode = {
      tag: { id: 40, name: 'Tech', color: null, icon: null, position: 0 },
      subscriptions: [sub(1, 3)],
      unreadCount: 3,
    };

    // The collapsed state persists in localStorage, so each test starts and
    // ends from a clean slate to keep the default-expanded assumption honest.
    beforeEach(() => localStorage.clear());
    afterEach(() => localStorage.clear());

    // The borderless header chevron is its own `.section-chevron`, never the
    // bordered `.chevzone` box the tag rows carry.
    const headChevronIcon = (el: HTMLElement) =>
      el.querySelector('.tags-head .section-chevron .material-symbols-outlined')!.textContent;

    it('shows the tags expanded by default, with a downward chevron on the header', () => {
      const el = mount({ tagTree: [tagNode] }).nativeElement as HTMLElement;
      const head = el.querySelector('.tags-head')!;
      expect(head.textContent).toContain('Tags');
      expect(head.querySelector('.section-toggle')!.getAttribute('aria-expanded')).toBe('true');
      expect(headChevronIcon(el)).toBe('expand_more');
      expect(head.querySelector('.chevzone')).toBeNull();
      expect(el.querySelector('.tags .taghead')).not.toBeNull();
    });

    it('collapses the list and points the chevron right when the title is clicked', () => {
      const f = mount({ tagTree: [tagNode] });
      const el = f.nativeElement as HTMLElement;
      (el.querySelector('.tags-head .section-toggle') as HTMLButtonElement).click();
      f.detectChanges();

      expect(el.querySelector('.tags-head .section-toggle')!.getAttribute('aria-expanded')).toBe(
        'false',
      );
      expect(headChevronIcon(el)).toBe('chevron_right');
      expect(el.querySelector('.tags .taghead')).toBeNull();
      // The header itself stays put so the section can be reopened.
      expect(el.querySelector('.tags-head')).not.toBeNull();
    });

    it('also collapses via the trailing chevron button', () => {
      const f = mount({ tagTree: [tagNode] });
      const el = f.nativeElement as HTMLElement;
      (el.querySelector('.tags-head .section-chevron') as HTMLButtonElement).click();
      f.detectChanges();

      expect(el.querySelector('.tags .taghead')).toBeNull();
    });

    it('restores the collapsed state on a fresh mount (persisted)', () => {
      const first = mount({ tagTree: [tagNode] });
      first.componentInstance.toggleTags();

      TestBed.resetTestingModule();
      const el = mount({ tagTree: [tagNode] }).nativeElement as HTMLElement;
      expect(el.querySelector('.tags-head .section-toggle')!.getAttribute('aria-expanded')).toBe(
        'false',
      );
      expect(el.querySelector('.tags .taghead')).toBeNull();
    });
  });

  describe('collapsible feed list', () => {
    beforeEach(() => localStorage.clear());
    afterEach(() => localStorage.clear());

    const headChevronIcon = (el: HTMLElement) =>
      el.querySelector('.feeds-head .section-chevron .material-symbols-outlined')!.textContent;

    it('shows the feeds expanded by default, with a downward chevron on the header', () => {
      const el = mount({ untagged: [sub(1, 2)] }).nativeElement as HTMLElement;
      const head = el.querySelector('.feeds-head')!;
      expect(head.textContent).toContain('Feeds');
      expect(head.querySelector('.section-toggle')!.getAttribute('aria-expanded')).toBe('true');
      expect(headChevronIcon(el)).toBe('expand_more');
      expect(el.querySelector('.feedlist .feedrow')).not.toBeNull();
    });

    it('collapses the untagged feeds and points the chevron right when clicked', () => {
      const f = mount({ untagged: [sub(1, 2)] });
      const el = f.nativeElement as HTMLElement;
      (el.querySelector('.feeds-head .section-toggle') as HTMLButtonElement).click();
      f.detectChanges();

      expect(headChevronIcon(el)).toBe('chevron_right');
      expect(el.querySelector('.feedlist .feedrow')).toBeNull();
      // The drop list itself stays mounted so an untag drag still has a target.
      expect(el.querySelector('.feedlist')).not.toBeNull();
    });

    it('also collapses via the trailing chevron button', () => {
      const f = mount({ untagged: [sub(1, 2)] });
      const el = f.nativeElement as HTMLElement;
      (el.querySelector('.feeds-head .section-chevron') as HTMLButtonElement).click();
      f.detectChanges();

      expect(el.querySelector('.feedlist .feedrow')).toBeNull();
    });

    it('reveals the feeds while a drag is in progress, even when collapsed', () => {
      const f = mount({ untagged: [sub(1, 2)] });
      const el = f.nativeElement as HTMLElement;
      f.componentInstance.toggleFeeds();
      f.detectChanges();
      expect(el.querySelector('.feedlist .feedrow')).toBeNull();

      f.componentInstance.dragging.set(true);
      f.detectChanges();
      expect(el.querySelector('.feedlist .feedrow')).not.toBeNull();
    });

    it('restores the collapsed state on a fresh mount (persisted)', () => {
      const first = mount({ untagged: [sub(1, 2)] });
      first.componentInstance.toggleFeeds();

      TestBed.resetTestingModule();
      const el = mount({ untagged: [sub(1, 2)] }).nativeElement as HTMLElement;
      expect(el.querySelector('.feeds-head .section-toggle')!.getAttribute('aria-expanded')).toBe(
        'false',
      );
      expect(el.querySelector('.feedlist .feedrow')).toBeNull();
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
    const f = TestBed.createComponent(SidebarComponent);
    f.componentRef.setInput('tagTree', []);
    f.componentRef.setInput('untagged', []);
    f.componentRef.setInput('totalUnread', 0);
    f.componentRef.setInput('selection', { kind: 'all', id: null, unread: true });
    f.componentRef.setInput('loading', false);
    f.componentRef.setInput('organising', false);
    f.detectChanges();
    return f;
  }

  it('is absent when AI is not ready', () => {
    const el = mountWithAi(false).nativeElement as HTMLElement;
    expect(el.querySelector('.nav.for-you')).toBeNull();
  });

  it('is present when AI is ready', () => {
    const el = mountWithAi(true).nativeElement as HTMLElement;
    expect(el.querySelector('.nav.for-you')).not.toBeNull();
  });

  it('pulses the icon while a recommendation run is in progress', () => {
    const el = mountWithAi(true, true).nativeElement as HTMLElement;
    expect(el.querySelector('.nav.for-you app-icon.pulse')).not.toBeNull();
  });

  it('shows the for-you item count as a badge', () => {
    const el = mountWithAi(true, false, 12).nativeElement as HTMLElement;
    expect(el.querySelector('.nav.for-you .count')!.textContent).toContain('12');
  });

  it('hides the badge when the for-you list is empty', () => {
    const el = mountWithAi(true, false, 0).nativeElement as HTMLElement;
    expect(el.querySelector('.nav.for-you .count')).toBeNull();
  });
});

describe('organise mode', () => {
  const tag: TagDto = { id: 1, name: 'News', color: null, icon: null, position: 0 };
  const tree: TagNode[] = [{ tag, subscriptions: [sub(5)], unreadCount: 3 }];

  it('offers the Organise switch on coarse pointers only', () => {
    const isCoarse = signal(false);
    TestBed.configureTestingModule({
      imports: [SidebarComponent, provideTranslocoTesting()],
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: AuthService, useValue: { user: signal(account(null)) } },
        { provide: LayoutService, useValue: { isCoarse, isNarrow: signal(false) } },
      ],
    });
    const f = TestBed.createComponent(SidebarComponent);
    f.componentRef.setInput('tagTree', []);
    f.componentRef.setInput('untagged', []);
    f.componentRef.setInput('totalUnread', 0);
    f.componentRef.setInput('selection', { kind: 'all', id: null, unread: true });
    f.componentRef.setInput('loading', false);
    f.componentRef.setInput('organising', false);
    f.detectChanges();
    const el = f.nativeElement as HTMLElement;
    expect(el.querySelector('.organise')).toBeNull();

    isCoarse.set(true);
    f.detectChanges();
    const organiseSwitch = el.querySelector('.organise')!;
    expect(organiseSwitch.getAttribute('role')).toBe('switch');
    expect(organiseSwitch.getAttribute('aria-checked')).toBe('false');
    expect(organiseSwitch.textContent).toContain('Organise');
  });

  it('clicking the switch flips the organising model', () => {
    const f = mount({ coarse: true });
    (f.nativeElement as HTMLElement).querySelector<HTMLElement>('.organise')!.click();
    f.detectChanges();
    expect(f.componentInstance.organising()).toBe(true);
    expect(
      (f.nativeElement as HTMLElement).querySelector('.organise')!.getAttribute('aria-checked'),
    ).toBe('true');
  });

  it('organising hides the actions, global views, view controls and trial line', () => {
    const el = mount({
      coarse: true,
      organising: true,
      tagTree: tree,
      user: account(inDays(5)),
    }).nativeElement as HTMLElement;
    expect(el.querySelector('.actions')).toBeNull();
    expect(el.querySelector('.nav.all')).toBeNull();
    expect(el.querySelector('app-view-controls')).toBeNull();
    expect(el.querySelector('.trial')).toBeNull();
    expect(el.querySelector('.version')).not.toBeNull();
    expect(el.querySelector('.tags')).not.toBeNull();
  });

  it('navigation mode keeps all of them', () => {
    const el = mount({
      coarse: true,
      tagTree: tree,
      user: account(inDays(5)),
    }).nativeElement as HTMLElement;
    expect(el.querySelector('.actions')).not.toBeNull();
    expect(el.querySelector('.nav.all')).not.toBeNull();
    expect(el.querySelector('app-view-controls')).not.toBeNull();
    expect(el.querySelector('.trial')).not.toBeNull();
  });

  it('organising always shows the Feeds label as the untag drop target', () => {
    const el = mount({ coarse: true, organising: true, untagged: [] }).nativeElement as HTMLElement;
    expect(el.textContent).toContain('Feeds');
  });

  it('coarse navigation shows the trailing chevron and no inline menu', () => {
    const el = mount({ coarse: true, tagTree: tree }).nativeElement as HTMLElement;
    const zone = el.querySelector('.tag .chevzone')!;
    expect(zone).not.toBeNull();
    expect(zone.getAttribute('aria-expanded')).toBe('false');
    expect(el.querySelector('.tag .nav.grow')).not.toBeNull();
    expect(el.querySelector('.dots')).toBeNull();
  });

  it('the chevron zone expands the tag without navigating', () => {
    const f = mount({ coarse: true, tagTree: tree });
    const el = f.nativeElement as HTMLElement;
    el.querySelector<HTMLElement>('.tag .chevzone')!.click();
    f.detectChanges();
    expect(el.querySelector('.tagfeeds')).not.toBeNull();
    expect(el.querySelector('.tag .chevzone')!.getAttribute('aria-expanded')).toBe('true');
    expect(TestBed.inject(Router).url).toBe('/'); // expand must not select the tag
  });

  it('organise rows carry a drag handle and expand via the row body', () => {
    const f = mount({ coarse: true, organising: true, tagTree: tree });
    const el = f.nativeElement as HTMLElement;
    expect(el.querySelector('.tag .handle')).not.toBeNull();
    expect(el.querySelector('.tag .nav.grow')).toBeNull();
    expect(el.querySelector('.tag .chevzone')).toBeNull();
    expect(el.querySelector('.tag .rowbody')!.getAttribute('aria-expanded')).toBe('false');
    el.querySelector<HTMLElement>('.tag .rowbody')!.click();
    f.detectChanges();
    expect(el.querySelector('.tag .rowbody')!.getAttribute('aria-expanded')).toBe('true');
    expect(el.querySelector('.tagfeeds')).not.toBeNull();
    expect(el.querySelector('.tagfeeds .handle')).not.toBeNull();
  });

  it('the tag dots open the action sheet and route the choice', () => {
    const f = mount({ coarse: true, organising: true, tagTree: tree, sheetChoice: 'delete' });
    const deleted = jest.fn();
    f.componentInstance.deleteTag.subscribe(deleted);
    f.nativeElement.querySelector('.tag .dots').click();
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

  it('the feed dots offer edit and unsubscribe', () => {
    const f = mount({ coarse: true, organising: true, untagged: [sub(9)], sheetChoice: 'edit' });
    const edited = jest.fn();
    f.componentInstance.editFeed.subscribe(edited);
    f.nativeElement.querySelector('.feedrow .dots').click();
    const sheet = TestBed.inject(ActionSheet);
    expect(sheet.open).toHaveBeenCalledWith({
      title: 's9',
      actions: [
        { id: 'edit', label: 'Edit feed' },
        { id: 'unsubscribe', label: 'Unsubscribe', danger: true },
      ],
    });
    expect(edited).toHaveBeenCalledWith(expect.objectContaining({ id: 9 }));
  });

  it('routes the tag edit choice to editTag and only that', () => {
    const f = mount({ coarse: true, organising: true, tagTree: tree, sheetChoice: 'edit' });
    const edited = jest.fn();
    const deleted = jest.fn();
    f.componentInstance.editTag.subscribe(edited);
    f.componentInstance.deleteTag.subscribe(deleted);
    f.nativeElement.querySelector('.tag .dots').click();
    expect(edited).toHaveBeenCalledWith(tag);
    expect(deleted).not.toHaveBeenCalled();
  });

  it('routes the feed unsubscribe choice to unsubscribe and only that', () => {
    const f = mount({
      coarse: true,
      organising: true,
      untagged: [sub(9)],
      sheetChoice: 'unsubscribe',
    });
    const unsubscribed = jest.fn();
    const edited = jest.fn();
    f.componentInstance.unsubscribe.subscribe(unsubscribed);
    f.componentInstance.editFeed.subscribe(edited);
    f.nativeElement.querySelector('.feedrow .dots').click();
    expect(unsubscribed).toHaveBeenCalledWith(expect.objectContaining({ id: 9 }));
    expect(edited).not.toHaveBeenCalled();
  });

  it('a dismissed sheet emits nothing', () => {
    const f = mount({ coarse: true, organising: true, tagTree: tree, sheetChoice: undefined });
    const emitted = jest.fn();
    f.componentInstance.editTag.subscribe(emitted);
    f.componentInstance.deleteTag.subscribe(emitted);
    f.nativeElement.querySelector('.tag .dots').click();
    expect(TestBed.inject(ActionSheet).open).toHaveBeenCalled();
    expect(emitted).not.toHaveBeenCalled();
  });

  it('keeps the foot order: organise, view controls, trial, meta', () => {
    const el = mount({ coarse: true, user: account(inDays(5)) }).nativeElement as HTMLElement;
    const order = Array.from(el.querySelector('.foot')!.children).map(
      (child) => child.classList[0],
    );
    expect(order).toEqual(['organise', 'controls', 'trial', 'meta']);
  });

  it('links Feedback to the public issue tracker in a new tab', () => {
    const feedback = (mount().nativeElement as HTMLElement).querySelector('.feedback');

    expect(feedback?.textContent?.trim()).toBe('Feedback');
    expect(feedback?.getAttribute('href')).toBe(
      'https://github.com/larspohlmann/simple-feed-reader/issues',
    );
    expect(feedback?.getAttribute('target')).toBe('_blank');
    expect(feedback?.getAttribute('rel')).toBe('noopener noreferrer');
  });

  it('resets organising when the pointer stops being coarse', () => {
    const isCoarse = signal(true);
    TestBed.configureTestingModule({
      imports: [SidebarComponent, provideTranslocoTesting()],
      providers: [
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: AuthService, useValue: { user: signal(account(null)) } },
        { provide: LayoutService, useValue: { isCoarse, isNarrow: signal(false) } },
        { provide: ActionSheet, useValue: { open: jest.fn(() => of(undefined)) } },
      ],
    });
    const f = TestBed.createComponent(SidebarComponent);
    f.componentRef.setInput('tagTree', tree);
    f.componentRef.setInput('untagged', []);
    f.componentRef.setInput('totalUnread', 0);
    f.componentRef.setInput('selection', { kind: 'all', id: null, unread: true });
    f.componentRef.setInput('loading', false);
    f.componentRef.setInput('organising', true);
    f.detectChanges();
    expect((f.nativeElement as HTMLElement).querySelector('.tag .handle')).not.toBeNull();

    isCoarse.set(false);
    f.detectChanges();
    // The exit switch only renders on coarse pointers, so a stuck true would
    // leave the organise DOM with no way out — the component resets instead.
    expect(f.componentInstance.organising()).toBe(false);
    expect((f.nativeElement as HTMLElement).querySelector('.tag .handle')).toBeNull();
    expect((f.nativeElement as HTMLElement).querySelector('.tag .chevzone')).not.toBeNull();
  });

  it('locks dragging in coarse navigation mode and frees it while organising', () => {
    function mountWithLayout(coarse: boolean, organising: boolean) {
      TestBed.resetTestingModule();
      TestBed.configureTestingModule({
        imports: [SidebarComponent, provideTranslocoTesting()],
        providers: [
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
      const f = TestBed.createComponent(SidebarComponent);
      f.componentRef.setInput('tagTree', tree);
      f.componentRef.setInput('untagged', []);
      f.componentRef.setInput('totalUnread', 0);
      f.componentRef.setInput('selection', { kind: 'all', id: null, unread: true });
      f.componentRef.setInput('loading', false);
      f.componentRef.setInput('organising', organising);
      f.detectChanges();
      return f;
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
    const el = mount({ tagTree: tree }).nativeElement as HTMLElement;
    expect(el.querySelector('.tag .chevzone')).not.toBeNull();
    expect(el.querySelector('.handle')).toBeNull();
    expect(el.querySelector('.rowmenu .dots')).not.toBeNull();
  });
});
