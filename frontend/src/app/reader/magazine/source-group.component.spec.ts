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
  imageUrl: null,
  imageWidth: null,
  imageHeight: null,
  publishedAt: null,
  createdAt: 'x',
  subscriptionId: 7,
  source: 'heise',
  faviconUrl: null,
  isRead: false,
  isFavorite: false,
  isKept: false,
});

describe('SourceGroupComponent', () => {
  function mount(entries: EntryDto[], previewCount: number) {
    TestBed.configureTestingModule({
      imports: [SourceGroupComponent, provideTranslocoTesting()],
      providers: [provideRouter([])],
    });
    const f = TestBed.createComponent(SourceGroupComponent);
    f.componentRef.setInput('source', 'heise');
    f.componentRef.setInput('subscriptionId', 7);
    f.componentRef.setInput('entries', entries);
    f.componentRef.setInput('previewCount', previewCount);
    f.componentRef.setInput('tags', []);
    f.detectChanges();
    return f;
  }

  it('previews previewCount rows and counts the hidden tail', () => {
    const el = mount([e(1), e(2), e(3), e(4), e(5), e(6), e(7)], 4).nativeElement as HTMLElement;
    expect(el.textContent).toContain('heise');
    expect(el.querySelectorAll('app-entry-compact').length).toBe(4);
    expect(el.querySelector('.more')!.textContent).toContain('3 more from heise');
  });

  it('renders no more indicator when the tail fits the preview', () => {
    const el = mount([e(1), e(2), e(3)], 3).nativeElement as HTMLElement;
    expect(el.querySelector('.more')).toBeNull();
  });

  it('shows the feed tags as pills once, on the group header', () => {
    const f = mount([e(1), e(2), e(3), e(4), e(5)], 4);
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
    const f = mount([e(1), e(2), e(3), e(4), e(5)], 4);
    const open = jest.fn();
    f.componentInstance.open.subscribe(open);
    (f.nativeElement.querySelector('.compact') as HTMLElement).click();
    expect(open).toHaveBeenCalled();
  });
});
