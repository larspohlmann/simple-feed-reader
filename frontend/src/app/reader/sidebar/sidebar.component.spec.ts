import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { SidebarComponent } from './sidebar.component';
import { TagNode } from '../subscriptions.store';
import { Selection } from '../query';
import { SubscriptionDto } from '../models';

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
  TestBed.configureTestingModule({ imports: [SidebarComponent], providers: [provideRouter([])] });
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
