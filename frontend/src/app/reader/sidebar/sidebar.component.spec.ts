import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../../core/api';
import { RefreshService } from '../refresh.service';
import { CdkDragDrop } from '@angular/cdk/drag-drop';
import { DropData, SidebarComponent } from './sidebar.component';
import { TagNode } from '../subscriptions.store';
import { Selection } from '../query';
import { SubscriptionDto, TagDto } from '../models';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';

const sub = (id: number, unread = 0): SubscriptionDto => ({
  id,
  title: `s${id}`,
  customTitle: null,
  feedUrl: `https://f/${id}`,
  siteUrl: null,
  status: 'active',
  sourceFormat: 'xml',
  createdAt: 'x',
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
  }> = {},
) {
  TestBed.configureTestingModule({
    imports: [SidebarComponent, provideTranslocoTesting()],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      { provide: API_BASE_URL, useValue: 'https://api.test' },
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
});
