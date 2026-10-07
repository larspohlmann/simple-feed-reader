import { Component, forwardRef } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { EntryActionsComponent } from './entry-actions.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { EntryDto } from '../../models';
import { EntryActionHandler } from './entry-action-handler';
import { AudioPlayerService } from '../../audio-player.service';
import { StubAudioPlayer } from '../../../../testing/stub-audio-player';

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 'A title',
  url: null,
  author: null,
  summary: null,
  excerpt: '',
  imageUrl: null,
  imageWidth: null,
  imageHeight: null,
  imageRenditions: [],
  media: [],
  attachments: [],
  categories: [],
  publishedAt: null,
  createdAt: 'x',
  subscriptionId: 7,
  source: 'Src',
  faviconUrl: null,
  isHidden: false,
  isFavorite: false,
  isKept: false,
  isViewed: false,
  discussionUrl: null,
  comments: null,
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
    <app-entry-actions [entry]="entry" [size]="size" [audio]="audio" />
  </article>`,
  providers: [{ provide: EntryActionHandler, useExisting: forwardRef(() => HostComponent) }],
})
class HostComponent implements EntryActionHandler {
  entry: EntryDto = entry();
  size: 'sm' | 'md' = 'sm';
  audio = true;
  cardOpened = false;
  favoriteCount = 0;
  kept: EntryDto | null = null;
  marked: EntryDto | null = null;

  favorite(): void {
    this.favoriteCount++;
  }

  keep(entry: EntryDto): void {
    this.kept = entry;
  }

  toggleRead(entry: EntryDto): void {
    this.marked = entry;
  }

  open(): void {
    throw new Error('entry-actions never opens its entry');
  }
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

function mount(testEntry: EntryDto = entry()) {
  const fixture = TestBed.createComponent(HostComponent);
  fixture.componentInstance.entry = testEntry;
  fixture.detectChanges();
  return fixture;
}

const buttons = (fixture: { nativeElement: HTMLElement }) =>
  Array.from(fixture.nativeElement.querySelectorAll('button'));

const iconSizes = (fixture: ReturnType<typeof mount>) =>
  fixture.debugElement
    .queryAll(By.directive(IconComponent))
    .map((icon) => icon.componentInstance.size());

const EPISODE = 'https://x.test/ep.mp3';
const episode = (): EntryDto =>
  entry({ title: 'Ep 1', attachments: [{ url: EPISODE, mimeType: 'audio/mpeg' }] });

describe('EntryActionsComponent', () => {
  let player: StubAudioPlayer;

  beforeEach(() => {
    player = new StubAudioPlayer();
    TestBed.configureTestingModule({
      imports: [HostComponent, provideTranslocoTesting()],
      providers: [{ provide: AudioPlayerService, useValue: player.asService() }],
    });
  });

  it('renders the three actions with their labels', () => {
    const labels = buttons(mount()).map((button) => button.getAttribute('aria-label'));
    expect(labels).toEqual(['Favorite', 'Keep', 'Toggle read']);
  });

  it('reports each action state through aria-pressed', () => {
    // The third toggle is the tick: it reflects "viewed", not "read" (#482).
    const fixture = mount(entry({ isFavorite: true, isKept: false, isViewed: true }));
    const pressed = buttons(fixture).map((button) => button.getAttribute('aria-pressed'));
    expect(pressed).toEqual(['true', 'false', 'true']);
  });

  it('marks every active toggle the same way, the tick one included', () => {
    const fixture = mount(entry({ isFavorite: true, isKept: true, isViewed: true }));
    const on = buttons(fixture).map((button) => button.classList.contains('on'));
    expect(on).toEqual([true, true, true]);
  });

  it('leaves an inactive toggle unmarked', () => {
    const fixture = mount(entry({ isFavorite: false, isKept: false, isHidden: false }));
    const on = buttons(fixture).map((button) => button.classList.contains('on'));
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
    const fixture = mount();
    const [favorite, keep, read] = buttons(fixture);

    favorite.click();
    keep.click();
    read.click();
    fixture.detectChanges();

    expect(fixture.componentInstance.favoriteCount).toBe(1);
    expect(fixture.componentInstance.kept).toBe(fixture.componentInstance.entry);
    expect(fixture.componentInstance.marked).toBe(fixture.componentInstance.entry);
    expect(fixture.componentInstance.cardOpened).toBe(false);
  });

  it('favorites exactly once on Enter, and does not open the card', () => {
    const fixture = mount();
    const [favorite] = buttons(fixture);

    pressEnter(favorite);
    fixture.detectChanges();

    expect(fixture.componentInstance.favoriteCount).toBe(1);
    expect(fixture.componentInstance.cardOpened).toBe(false);
  });

  it('favorites exactly once on Space, and does not open the card', () => {
    const fixture = mount();
    const [favorite] = buttons(fixture);

    pressSpace(favorite);
    fixture.detectChanges();

    expect(fixture.componentInstance.favoriteCount).toBe(1);
    expect(fixture.componentInstance.cardOpened).toBe(false);
  });

  it('renders sm glyphs by default, so the magazine cards are unchanged', () => {
    const fixture = mount();
    expect(iconSizes(fixture)).toEqual(['sm', 'sm', 'sm']);
    expect(fixture.nativeElement.querySelector('app-entry-actions')!.classList).not.toContain(
      'glyph-md',
    );
  });

  it('renders md glyphs on request, and advertises it for the tap-target math', () => {
    const fixture = mount();
    fixture.componentInstance.size = 'md';
    fixture.detectChanges();

    expect(iconSizes(fixture)).toEqual(['md', 'md', 'md']);
    expect(fixture.nativeElement.querySelector('app-entry-actions')!.classList).toContain(
      'glyph-md',
    );
  });

  it('fills the star and bookmark only while they are on', () => {
    const fills = (testEntry: EntryDto) =>
      mount(testEntry)
        .debugElement.queryAll(By.directive(IconComponent))
        .map((icon) => icon.componentInstance.fill());

    expect(fills(entry({ isFavorite: false, isKept: false }))).toEqual([false, false, false]);
    expect(fills(entry({ isFavorite: true, isKept: true, isViewed: true }))).toEqual([
      true,
      true,
      false,
    ]);
  });

  it('styles every toggle through the shared flag-toggle look', () => {
    const shared = buttons(mount()).map((button) => button.classList.contains('flag-toggle'));
    expect(shared).toEqual([true, true, true]);
  });

  describe('on an audio entry (#1436)', () => {
    const labels = (fixture: ReturnType<typeof mount>) =>
      buttons(fixture).map((button) => button.getAttribute('aria-label'));

    it('leads with play and add to playlist', () => {
      expect(labels(mount(episode()))).toEqual([
        'Play',
        'Add to playlist',
        'Favorite',
        'Keep',
        'Toggle read',
      ]);
    });

    it('leaves them out where the host has its own listen controls', () => {
      const fixture = TestBed.createComponent(HostComponent);
      fixture.componentInstance.entry = episode();
      fixture.componentInstance.audio = false;
      fixture.detectChanges();
      expect(labels(fixture)).toEqual(['Favorite', 'Keep', 'Toggle read']);
    });

    it('plays the episode without opening the card, by click, Enter or Space', () => {
      const fixture = mount(episode());
      const [play] = buttons(fixture);

      play.click();
      pressEnter(play);
      pressSpace(play);

      expect(player.played.map((track) => track.url)).toEqual([EPISODE, EPISODE, EPISODE]);
      expect(fixture.componentInstance.cardOpened).toBe(false);
    });

    it('shows pause while the episode plays, and pauses it', () => {
      const fixture = mount(episode());
      player.current.set({
        url: EPISODE,
        title: 'Ep 1',
        faviconUrl: null,
        imageUrl: null,
        durationInSeconds: null,
      });
      player.playing.set(true);
      fixture.detectChanges();
      const [pause] = buttons(fixture);
      expect(pause.getAttribute('aria-label')).toBe('Pause');
      expect(pause.textContent).toContain('pause');

      pause.click();

      expect(player.toggles).toBe(1);
      expect(player.played).toEqual([]);
    });

    it('adds the episode to the playlist and, once queued, takes it out again', () => {
      const fixture = mount(episode());
      const queue = () => buttons(fixture)[1];

      queue().click();
      fixture.detectChanges();
      expect(player.queue()).toEqual([EPISODE]);
      expect(queue().getAttribute('aria-label')).toBe('In playlist');
      expect(queue().getAttribute('aria-pressed')).toBe('true');
      expect(queue().textContent).toContain('playlist_add_check');

      queue().click();
      fixture.detectChanges();
      expect(player.queue()).toEqual([]);
      expect(queue().getAttribute('aria-label')).toBe('Add to playlist');
      expect(fixture.componentInstance.cardOpened).toBe(false);
    });
  });
});
