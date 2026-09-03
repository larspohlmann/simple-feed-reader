import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { EntryActionsComponent } from './entry-actions.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { EntryDto } from '../models';

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 'A title',
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
  source: 'Src',
  faviconUrl: null,
  isHidden: false,
  isFavorite: false,
  isKept: false,
  isViewed: false,
  ...over,
});

/** Stand-in for the card: proves an action click never reaches it. The keydown
 *  bindings mirror the real magazine card's `<article>` wiring exactly
 *  (`entry-compact.component.html`), Space's `preventDefault()` included. */
@Component({
  imports: [EntryActionsComponent],
  template: `<article
    class="card"
    role="button"
    tabindex="0"
    (click)="cardOpened = true"
    (keydown.enter)="cardOpened = true"
    (keydown.space)="$event.preventDefault(); cardOpened = true"
  >
    <app-entry-actions
      [entry]="entry"
      [size]="size"
      (favorite)="favoriteCount = favoriteCount + 1"
      (keep)="kept = $event"
      (read)="marked = $event"
    />
  </article>`,
})
class HostComponent {
  entry: EntryDto = entry();
  size: 'sm' | 'md' = 'sm';
  cardOpened = false;
  favoriteCount = 0;
  kept: EntryDto | null = null;
  marked: EntryDto | null = null;
}

/** jsdom does not fire `click` for a focused button's Enter/Space itself, so
 *  this reproduces it: Enter's click follows keydown, Space's follows keyup —
 *  skipped once that governing event's default was prevented. */
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

function mount(e: EntryDto = entry()) {
  const f = TestBed.createComponent(HostComponent);
  f.componentInstance.entry = e;
  f.detectChanges();
  return f;
}

const buttons = (f: { nativeElement: HTMLElement }) =>
  Array.from(f.nativeElement.querySelectorAll('button'));

const iconSizes = (f: ReturnType<typeof mount>) =>
  f.debugElement.queryAll(By.directive(IconComponent)).map((d) => d.componentInstance.size());

describe('EntryActionsComponent', () => {
  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [HostComponent, provideTranslocoTesting()] });
  });

  it('renders the three actions with their labels', () => {
    const labels = buttons(mount()).map((b) => b.getAttribute('aria-label'));
    expect(labels).toEqual(['Favorite', 'Keep', 'Toggle read']);
  });

  it('reports each action state through aria-pressed', () => {
    // The third toggle is the tick: it reflects "viewed", not "read" (#482).
    const f = mount(entry({ isFavorite: true, isKept: false, isViewed: true }));
    const pressed = buttons(f).map((b) => b.getAttribute('aria-pressed'));
    expect(pressed).toEqual(['true', 'false', 'true']);
  });

  it('marks every active toggle the same way, the tick one included', () => {
    const f = mount(entry({ isFavorite: true, isKept: true, isViewed: true }));
    const on = buttons(f).map((b) => b.classList.contains('on'));
    expect(on).toEqual([true, true, true]);
  });

  it('leaves an inactive toggle unmarked', () => {
    const f = mount(entry({ isFavorite: false, isKept: false, isHidden: false }));
    const on = buttons(f).map((b) => b.classList.contains('on'));
    expect(on).toEqual([false, false, false]);
  });

  it('keeps one glyph per toggle, so only the colour moves', () => {
    // The read button swapped to an envelope once read; the state is carried by
    // the accent now, exactly as favorite and keep carry theirs (#435).
    for (const isHidden of [false, true]) {
      const text = mount(entry({ isHidden })).nativeElement.textContent;
      expect(text).toContain('check');
      expect(text).not.toContain('mark_email_unread');
    }
  });

  it('emits the entry and does not open the card', () => {
    const f = mount();
    const [favorite, keep, read] = buttons(f);

    favorite.click();
    keep.click();
    read.click();
    f.detectChanges();

    expect(f.componentInstance.favoriteCount).toBe(1);
    expect(f.componentInstance.kept).toBe(f.componentInstance.entry);
    expect(f.componentInstance.marked).toBe(f.componentInstance.entry);
    expect(f.componentInstance.cardOpened).toBe(false);
  });

  it('favorites exactly once on Enter, and does not open the card', () => {
    const f = mount();
    const [favorite] = buttons(f);

    pressEnter(favorite);
    f.detectChanges();

    expect(f.componentInstance.favoriteCount).toBe(1);
    expect(f.componentInstance.cardOpened).toBe(false);
  });

  it('favorites exactly once on Space, and does not open the card', () => {
    const f = mount();
    const [favorite] = buttons(f);

    pressSpace(favorite);
    f.detectChanges();

    expect(f.componentInstance.favoriteCount).toBe(1);
    expect(f.componentInstance.cardOpened).toBe(false);
  });

  it('renders sm glyphs by default, so the magazine cards are unchanged', () => {
    const f = mount();
    expect(iconSizes(f)).toEqual(['sm', 'sm', 'sm']);
    expect(f.nativeElement.querySelector('app-entry-actions')!.classList).not.toContain('glyph-md');
  });

  it('renders md glyphs on request, and advertises it for the tap-target math', () => {
    const f = mount();
    f.componentInstance.size = 'md';
    f.detectChanges();

    expect(iconSizes(f)).toEqual(['md', 'md', 'md']);
    expect(f.nativeElement.querySelector('app-entry-actions')!.classList).toContain('glyph-md');
  });
});
