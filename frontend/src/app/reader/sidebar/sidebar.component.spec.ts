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
});
