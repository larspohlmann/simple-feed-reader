import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { API_BASE_URL } from '../../core/api';
import { AuthService, CurrentUser } from '../../core/auth.service';
import { RefreshService } from '../refresh.service';
import { CdkDrag, CdkDragDrop } from '@angular/cdk/drag-drop';
import { DropData, SidebarComponent } from './sidebar.component';
import { TagNode } from '../subscriptions.store';
import { Selection } from '../query';
import { SubscriptionDto, TagDto } from '../models';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { buildVersion } from '../../../environments/version';
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
    organising: boolean;
    sheetChoice?: string;
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
      { provide: LayoutService, useValue: { isCoarse: signal(over.coarse ?? false) } },
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
  f.componentRef.setInput('organising', over.organising ?? false);
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
    (el.querySelector('.tag .expand') as HTMLButtonElement).click();
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
    el.querySelectorAll<HTMLButtonElement>('.tag .expand').forEach((b) => b.click());
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

  it('shows the running build as a link into settings', () => {
    const version = (mount().nativeElement as HTMLElement).querySelector('.version');

    expect(version?.textContent?.trim()).toBe(buildVersion.version);
    expect(version?.getAttribute('href')).toBe('/settings');
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
        { provide: LayoutService, useValue: { isCoarse } },
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

  it('coarse navigation trades the leading chevron for a 44px trailing zone', () => {
    const el = mount({ coarse: true, tagTree: tree }).nativeElement as HTMLElement;
    expect(el.querySelector('.expand')).toBeNull();
    const zone = el.querySelector('.chevzone')!;
    expect(zone.getAttribute('aria-expanded')).toBe('false');
    expect(el.querySelector('.tag .nav.grow')).not.toBeNull();
    expect(el.querySelector('.dots')).toBeNull();
  });

  it('the chevron zone expands the tag without navigating', () => {
    const f = mount({ coarse: true, tagTree: tree });
    const el = f.nativeElement as HTMLElement;
    el.querySelector<HTMLElement>('.chevzone')!.click();
    f.detectChanges();
    expect(el.querySelector('.tagfeeds')).not.toBeNull();
    expect(el.querySelector('.chevzone')!.getAttribute('aria-expanded')).toBe('true');
    expect(TestBed.inject(Router).url).toBe('/'); // expand must not select the tag
  });

  it('organise rows carry a drag handle and expand via the row body', () => {
    const f = mount({ coarse: true, organising: true, tagTree: tree });
    const el = f.nativeElement as HTMLElement;
    expect(el.querySelector('.tag .handle')).not.toBeNull();
    expect(el.querySelector('.tag .nav.grow')).toBeNull();
    expect(el.querySelector('.chevzone')).toBeNull();
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
        { provide: LayoutService, useValue: { isCoarse } },
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
    expect((f.nativeElement as HTMLElement).querySelector('.expand')).not.toBeNull();
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
          { provide: LayoutService, useValue: { isCoarse: signal(coarse) } },
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

  it('desktop keeps the leading chevron, inline menu and popover', () => {
    const el = mount({ tagTree: tree }).nativeElement as HTMLElement;
    expect(el.querySelector('.expand')).not.toBeNull();
    expect(el.querySelector('.chevzone')).toBeNull();
    expect(el.querySelector('.handle')).toBeNull();
    expect(el.querySelector('.rowmenu .dots')).not.toBeNull();
  });
});
