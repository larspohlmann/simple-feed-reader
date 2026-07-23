import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { SourceGroupComponent } from './source-group.component';
import { EntryDto } from '../models';

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
      imports: [SourceGroupComponent],
      providers: [provideRouter([])],
    });
    const f = TestBed.createComponent(SourceGroupComponent);
    f.componentRef.setInput('source', 'heise');
    f.componentRef.setInput('subscriptionId', 7);
    f.componentRef.setInput('entries', [e(1), e(2), e(3)]);
    f.componentRef.setInput('moreCount', moreCount);
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

  it('re-emits open from an inner item', () => {
    const f = mount(1);
    const open = jest.fn();
    f.componentInstance.open.subscribe(open);
    (f.nativeElement.querySelector('.compact') as HTMLElement).click();
    expect(open).toHaveBeenCalled();
  });
});
