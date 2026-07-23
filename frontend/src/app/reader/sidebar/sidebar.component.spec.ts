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

const sub = (id: number, unread = 0): SubscriptionDto => ({
  id,
  title: `s${id}`,
  customTitle: null,
  feedUrl: `https://f/${id}`,
  siteUrl: null,
  status: 'active',
  createdAt: 'x',
  tags: [],
  unreadCount: unread,
});

function mount(
  over: Partial<{
    tagTree: TagNode[];
    untagged: SubscriptionDto[];
    totalUnread: number;
    selection: Selection;
  }> = {},
) {
  TestBed.configureTestingModule({
    imports: [SidebarComponent],
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
      tag: { id: 20, name: 'Tech', color: null, icon: null },
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

  it('emits editTag / deleteTag when a tag row menu action is used', () => {
    const node: TagNode = {
      tag: { id: 20, name: 'Tech', color: null, icon: null },
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
          tag: { id: 20, name: 'Tech', color: null, icon: null },
          subscriptions: [shared],
          unreadCount: 0,
        },
        {
          tag: { id: 21, name: 'News', color: null, icon: null },
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
    const tag = (id: number): TagDto => ({ id, name: `t${id}`, color: null, icon: null });
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
