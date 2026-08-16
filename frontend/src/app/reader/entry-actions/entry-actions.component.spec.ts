import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { EntryActionsComponent } from './entry-actions.component';
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
  isRead: false,
  isFavorite: false,
  isKept: false,
  isViewed: false,
  ...over,
});

/** A stand-in for the card: clickable, and it must NOT open when an action is
 *  pressed. Testing that through a real parent is the only way to prove the
 *  click never reaches the card. The two keydown bindings mirror exactly what
 *  every real magazine card binds on its own `<article>` (see
 *  `entry-compact.component.html`), `preventDefault()` on Space included — a
 *  host that does not reproduce that wiring would not exercise the bug. */
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
      (favorite)="favoriteCount = favoriteCount + 1"
      (keep)="kept = $event"
      (read)="marked = $event"
    />
  </article>`,
})
class HostComponent {
  entry: EntryDto = entry();
  cardOpened = false;
  favoriteCount = 0;
  kept: EntryDto | null = null;
  marked: EntryDto | null = null;
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

function mount(e: EntryDto = entry()) {
  const f = TestBed.createComponent(HostComponent);
  f.componentInstance.entry = e;
  f.detectChanges();
  return f;
}

const buttons = (f: { nativeElement: HTMLElement }) =>
  Array.from(f.nativeElement.querySelectorAll('button'));

describe('EntryActionsComponent', () => {
  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [HostComponent, provideTranslocoTesting()] });
  });

  it('renders the three actions with their labels', () => {
    const labels = buttons(mount()).map((b) => b.getAttribute('aria-label'));
    expect(labels).toEqual(['Favorite', 'Keep', 'Toggle read']);
  });

  it('reports each action state through aria-pressed', () => {
    const f = mount(entry({ isFavorite: true, isKept: false, isRead: true }));
    const pressed = buttons(f).map((b) => b.getAttribute('aria-pressed'));
    expect(pressed).toEqual(['true', 'false', 'true']);
  });

  it('marks every active toggle the same way, the read one included', () => {
    const f = mount(entry({ isFavorite: true, isKept: true, isRead: true }));
    const on = buttons(f).map((b) => b.classList.contains('on'));
    expect(on).toEqual([true, true, true]);
  });

  it('leaves an inactive toggle unmarked', () => {
    const f = mount(entry({ isFavorite: false, isKept: false, isRead: false }));
    const on = buttons(f).map((b) => b.classList.contains('on'));
    expect(on).toEqual([false, false, false]);
  });

  it('keeps one glyph per toggle, so only the colour moves', () => {
    // The read button swapped to an envelope once read; the state is carried by
    // the accent now, exactly as favorite and keep carry theirs (#435).
    for (const isRead of [false, true]) {
      const text = mount(entry({ isRead })).nativeElement.textContent;
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
});
