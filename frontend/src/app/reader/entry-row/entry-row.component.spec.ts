import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { EntryRowComponent } from './entry-row.component';
import { EntryActionsComponent } from '../entry-actions/entry-actions.component';
import { EntryDto } from '../models';

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 'Hello',
  url: 'https://x/1',
  author: null,
  summary: '<p>Summary text</p>',
  contentHtml: '<img src="https://cdn.test/a.jpg"><p>Body</p>',
  imageUrl: null,
  imageWidth: null,
  imageHeight: null,
  publishedAt: '2026-07-22T11:00:00Z',
  createdAt: 'x',
  subscriptionId: 5,
  source: 'heise',
  faviconUrl: null,
  isHidden: false,
  isFavorite: false,
  isKept: false,
  isViewed: false,
  ...over,
});

function mount(e: EntryDto) {
  const f = TestBed.createComponent(EntryRowComponent);
  f.componentRef.setInput('entry', e);
  f.detectChanges();
  return f;
}

/** Reproduces what a real browser does with a focused `<button>`, which jsdom
 *  does not simulate on its own: Enter fires `click` as part of the keydown's
 *  default action, Space defers it to the keyup's default action — and either
 *  is skipped once its governing keydown (and, for Space, keyup too) had
 *  `preventDefault()` called on it. */
function pressEnter(target: HTMLElement): void {
  const keydown = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
  target.dispatchEvent(keydown);
  if (!keydown.defaultPrevented) {
    target.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
  }
}

function pressSpace(target: HTMLElement): void {
  const keydown = new KeyboardEvent('keydown', { key: ' ', bubbles: true, cancelable: true });
  target.dispatchEvent(keydown);
  const keyup = new KeyboardEvent('keyup', { key: ' ', bubbles: true, cancelable: true });
  target.dispatchEvent(keyup);
  if (!keydown.defaultPrevented && !keyup.defaultPrevented) {
    target.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
  }
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

  it('shows the persisted imageUrl when the body has no inline image', () => {
    const el = mount(
      entry({
        contentHtml: '<p>no image</p>',
        summary: '<p>x</p>',
        imageUrl: 'https://cdn.test/hero.jpg',
      }),
    ).nativeElement as HTMLElement;
    expect(el.querySelector('img.thumb')!.getAttribute('src')).toBe('https://cdn.test/hero.jpg');
  });

  it('prefers the persisted imageUrl over a differing inline image', () => {
    const el = mount(
      entry({
        contentHtml: '<img src="https://cdn.test/inline.jpg"><p>Body</p>',
        imageUrl: 'https://cdn.test/hero.jpg',
      }),
    ).nativeElement as HTMLElement;
    expect(el.querySelector('img.thumb')!.getAttribute('src')).toBe('https://cdn.test/hero.jpg');
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

  it('keeps the list its larger md action glyphs', () => {
    const actions = mount(entry()).debugElement.query(By.directive(EntryActionsComponent));
    expect(actions.componentInstance.size()).toBe('md');
  });

  it('favorites exactly once on Enter over an action, and does not open the entry', () => {
    const f = mount(entry());
    const out = { favorite: 0, open: 0 };
    f.componentInstance.favorite.subscribe(() => out.favorite++);
    f.componentInstance.open.subscribe(() => out.open++);

    pressEnter(f.nativeElement.querySelector('[aria-label="Favorite"]') as HTMLElement);
    f.detectChanges();

    expect(out).toEqual({ favorite: 1, open: 0 });
  });

  it('favorites exactly once on Space over an action, and does not open the entry', () => {
    const f = mount(entry());
    const out = { favorite: 0, open: 0 };
    f.componentInstance.favorite.subscribe(() => out.favorite++);
    f.componentInstance.open.subscribe(() => out.open++);

    pressSpace(f.nativeElement.querySelector('[aria-label="Favorite"]') as HTMLElement);
    f.detectChanges();

    expect(out).toEqual({ favorite: 1, open: 0 });
  });

  it('names the saved search the entry came from (#769)', () => {
    const el = mount(entry({ savedSearchTerm: 'climate' })).nativeElement as HTMLElement;
    const pill = el.querySelector('.saved-search-pill')!;
    expect(pill.textContent).toContain('climate');
    expect(pill.getAttribute('title')).toBe('climate');
  });

  it('renders no pill outside the combined saved-search list (#769)', () => {
    const el = mount(entry()).nativeElement as HTMLElement;
    expect(el.querySelector('.saved-search-pill')).toBeNull();
  });
});
