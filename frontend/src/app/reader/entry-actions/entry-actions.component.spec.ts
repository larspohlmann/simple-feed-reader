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
 *  click never reaches the card. */
@Component({
  imports: [EntryActionsComponent],
  template: `<article
    class="card"
    role="button"
    tabindex="0"
    (click)="cardOpened = true"
    (keyup.enter)="cardOpened = true"
  >
    <app-entry-actions
      [entry]="entry"
      (favorite)="favorited = $event"
      (keep)="kept = $event"
      (read)="marked = $event"
    />
  </article>`,
})
class HostComponent {
  entry: EntryDto = entry();
  cardOpened = false;
  favorited: EntryDto | null = null;
  kept: EntryDto | null = null;
  marked: EntryDto | null = null;
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

  it('marks an active favorite and keep, but never the read button', () => {
    const f = mount(entry({ isFavorite: true, isKept: true, isRead: true }));
    const on = buttons(f).map((b) => b.classList.contains('on'));
    expect(on).toEqual([true, true, false]);
  });

  it('offers to mark read while unread, and to unread once read', () => {
    expect(mount(entry({ isRead: false })).nativeElement.textContent).toContain('check');
    expect(mount(entry({ isRead: true })).nativeElement.textContent).toContain('mark_email_unread');
  });

  it('emits the entry and does not open the card', () => {
    const f = mount();
    const [favorite, keep, read] = buttons(f);

    favorite.click();
    keep.click();
    read.click();
    f.detectChanges();

    expect(f.componentInstance.favorited).toBe(f.componentInstance.entry);
    expect(f.componentInstance.kept).toBe(f.componentInstance.entry);
    expect(f.componentInstance.marked).toBe(f.componentInstance.entry);
    expect(f.componentInstance.cardOpened).toBe(false);
  });
});
