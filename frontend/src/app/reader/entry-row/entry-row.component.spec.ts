import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { EntryRowComponent } from './entry-row.component';
import { EntryDto } from '../models';

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 'Hello',
  url: 'https://x/1',
  author: null,
  summary: '<p>Summary text</p>',
  contentHtml: '<img src="https://cdn.test/a.jpg"><p>Body</p>',
  publishedAt: '2026-07-22T11:00:00Z',
  createdAt: 'x',
  subscriptionId: 5,
  source: 'heise',
  isRead: false,
  isFavorite: false,
  isKept: false,
  ...over,
});

function mount(e: EntryDto) {
  const f = TestBed.createComponent(EntryRowComponent);
  f.componentRef.setInput('entry', e);
  f.detectChanges();
  return f;
}

describe('EntryRowComponent', () => {
  beforeEach(() =>
    TestBed.configureTestingModule({ imports: [EntryRowComponent, provideTranslocoTesting()] }),
  );

  it('renders title, source, snippet and the https thumbnail', () => {
    const el = mount(entry()).nativeElement as HTMLElement;
    expect(el.querySelector('.title')!.textContent).toContain('Hello');
    expect(el.querySelector('.meta')!.textContent).toContain('heise');
    expect(el.querySelector('.snippet')!.textContent).toContain('Summary text');
    expect(el.querySelector('img.thumb')!.getAttribute('src')).toBe('https://cdn.test/a.jpg');
  });

  it('omits the thumbnail when no https image exists', () => {
    const el = mount(entry({ contentHtml: '<p>no image</p>', summary: '<p>x</p>' }))
      .nativeElement as HTMLElement;
    expect(el.querySelector('img.thumb')).toBeNull();
  });

  it('moves the thumbnail to the left when imageSide is left', () => {
    const f = mount(entry());
    f.componentRef.setInput('imageSide', 'left');
    f.detectChanges();
    const el = f.nativeElement as HTMLElement;
    expect(el.querySelector('.row')!.classList).toContain('img-left');
  });

  it('emits actions and open', () => {
    const f = mount(entry());
    const out = { favorite: 0, keep: 0, read: 0, open: 0 };
    f.componentInstance.favorite.subscribe(() => out.favorite++);
    f.componentInstance.keep.subscribe(() => out.keep++);
    f.componentInstance.read.subscribe(() => out.read++);
    f.componentInstance.open.subscribe(() => out.open++);
    const el = f.nativeElement as HTMLElement;
    (el.querySelector('[aria-label="Favorite"]') as HTMLButtonElement).click();
    (el.querySelector('[aria-label="Keep"]') as HTMLButtonElement).click();
    (el.querySelector('[aria-label="Toggle read"]') as HTMLButtonElement).click();
    (el.querySelector('.row') as HTMLElement).click();
    expect(out).toEqual({ favorite: 1, keep: 1, read: 1, open: 1 });
  });
});
