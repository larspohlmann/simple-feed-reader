import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { provideRouter } from '@angular/router';
import { SourceGroupComponent } from './source-group.component';
import { EntryDto, SubscriptionTagDto } from '../models';

const tag = (id: number, name: string): SubscriptionTagDto => ({
  id,
  name,
  color: null,
  icon: null,
  position: 0,
});

const e = (id: number): EntryDto => ({
  id,
  title: `t${id}`,
  url: null,
  author: null,
  summary: null,
  contentHtml: null,
  publishedAt: null,
  createdAt: 'x',
  subscriptionId: 7,
  source: 'heise',
  isRead: false,
  isFavorite: false,
  isKept: false,
});

describe('SourceGroupComponent', () => {
  function mount(moreCount: number) {
    TestBed.configureTestingModule({
      imports: [SourceGroupComponent, provideTranslocoTesting()],
      providers: [provideRouter([])],
    });
    const f = TestBed.createComponent(SourceGroupComponent);
    f.componentRef.setInput('source', 'heise');
    f.componentRef.setInput('subscriptionId', 7);
    f.componentRef.setInput('entries', [e(1), e(2), e(3)]);
    f.componentRef.setInput('moreCount', moreCount);
    f.componentRef.setInput('tags', []);
    f.detectChanges();
    return f;
  }

  it('renders the source, three items, and a counted more link', () => {
    const el = mount(4).nativeElement as HTMLElement;
    expect(el.textContent).toContain('heise');
    expect(el.querySelectorAll('app-entry-compact').length).toBe(3);
    expect(el.querySelector('.more')!.textContent).toContain('4 more from heise');
  });

  it('drops the count when moreCount is 0', () => {
    const el = mount(0).nativeElement as HTMLElement;
    expect(el.querySelector('.more')!.textContent).toContain('More from heise');
    expect(el.querySelector('.more')!.textContent).not.toContain('0 more');
  });

  it('shows the feed tags as pills once, on the group header', () => {
    TestBed.configureTestingModule({
      imports: [SourceGroupComponent, provideTranslocoTesting()],
      providers: [provideRouter([])],
    });
    const f = TestBed.createComponent(SourceGroupComponent);
    f.componentRef.setInput('source', 'heise');
    f.componentRef.setInput('subscriptionId', 7);
    f.componentRef.setInput('entries', [e(1), e(2), e(3)]);
    f.componentRef.setInput('moreCount', 1);
    f.componentRef.setInput('tags', [tag(2, 'Tech')]);
    f.detectChanges();
    const el = f.nativeElement as HTMLElement;
    const pills = el.querySelectorAll('a.pill');
    expect(pills.length).toBe(1);
    expect(pills[0].textContent).toContain('Tech');
    // The header carries the pills; the inner compacts do not repeat them.
    expect(el.querySelector('.ghead a.pill')).not.toBeNull();
  });

  it('re-emits open from an inner item', () => {
    const f = mount(1);
    const open = jest.fn();
    f.componentInstance.open.subscribe(open);
    (f.nativeElement.querySelector('.compact') as HTMLElement).click();
    expect(open).toHaveBeenCalled();
  });
});
