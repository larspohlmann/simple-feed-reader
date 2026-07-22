import { TestBed } from '@angular/core/testing';
import { ReaderViewComponent } from './reader-view.component';
import { EntryDto } from '../models';

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 'Deep dive',
  url: 'https://x/1',
  author: 'Ada',
  summary: null,
  contentHtml: '<p>Body</p><a href="https://ext.test/z">link</a>',
  publishedAt: '2026-07-22T11:00:00Z',
  createdAt: 'x',
  subscriptionId: 5,
  source: 'Ars',
  isRead: false,
  isFavorite: false,
  isKept: false,
  ...over,
});

function mount(e: EntryDto | null, hasPrev = true, hasNext = true) {
  const f = TestBed.createComponent(ReaderViewComponent);
  f.componentRef.setInput('entry', e);
  f.componentRef.setInput('hasPrev', hasPrev);
  f.componentRef.setInput('hasNext', hasNext);
  f.detectChanges();
  return f;
}

describe('ReaderViewComponent', () => {
  beforeEach(() => TestBed.configureTestingModule({ imports: [ReaderViewComponent] }));

  it('renders title, meta, content and decorates external links', () => {
    const el = mount(entry()).nativeElement as HTMLElement;
    expect(el.querySelector('.title')!.textContent).toContain('Deep dive');
    expect(el.querySelector('.meta')!.textContent).toContain('Ars');
    expect(el.querySelector('.content')!.textContent).toContain('Body');
    const a = el.querySelector('.content a') as HTMLAnchorElement;
    expect(a.target).toBe('_blank');
    expect(a.rel).toContain('noopener');
  });

  it('leaves in-page fragment anchors undecorated', () => {
    const el = mount(
      entry({ contentHtml: '<a href="#footnote">jump</a><a href="https://ext.test/z">ext</a>' }),
    ).nativeElement as HTMLElement;
    const anchors = el.querySelectorAll('.content a');
    expect((anchors[0] as HTMLAnchorElement).target).toBe(''); // fragment link untouched
    expect((anchors[1] as HTMLAnchorElement).target).toBe('_blank'); // external decorated
  });

  it('emits favorite/keep/read/prev/next/close', () => {
    const f = mount(entry());
    const c = { favorite: 0, keep: 0, read: 0, prev: 0, next: 0, close: 0 };
    (Object.keys(c) as (keyof typeof c)[]).forEach((k) =>
      f.componentInstance[k].subscribe(() => c[k]++),
    );
    const el = f.nativeElement as HTMLElement;
    (el.querySelector('[aria-label="Favorite"]') as HTMLButtonElement).click();
    (el.querySelector('[aria-label="Keep"]') as HTMLButtonElement).click();
    (el.querySelector('[aria-label="Toggle read"]') as HTMLButtonElement).click();
    (el.querySelector('.prev') as HTMLButtonElement).click();
    (el.querySelector('.next') as HTMLButtonElement).click();
    (el.querySelector('.close') as HTMLButtonElement).click();
    expect(c).toEqual({ favorite: 1, keep: 1, read: 1, prev: 1, next: 1, close: 1 });
  });

  it('disables prev/next at the ends', () => {
    const el = mount(entry(), false, false).nativeElement as HTMLElement;
    expect((el.querySelector('.prev') as HTMLButtonElement).disabled).toBe(true);
    expect((el.querySelector('.next') as HTMLButtonElement).disabled).toBe(true);
  });
});
