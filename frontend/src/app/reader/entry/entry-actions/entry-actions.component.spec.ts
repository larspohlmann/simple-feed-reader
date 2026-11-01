import { Component, forwardRef } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { TranslocoService } from '@jsverse/transloco';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { EntryActionsComponent } from './entry-actions.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { EntryDto } from '../../models';
import { EntryActionHandler } from './entry-action-handler';
import { AudioPlayerService } from '../../audio-player.service';
import { StubAudioPlayer } from '../../../../testing/stub-audio-player';
import { provideAccountIdentity } from '../../../../testing/account-identity-testing';
import { anEntry } from '../../../../testing/entry-testing';
import { OpensEntryDirective } from '../../list/opens-entry.directive';

/** Stand-in for the card, wired like the real ones: proves an action never reaches it. */
@Component({
  imports: [EntryActionsComponent, OpensEntryDirective],
  template: `<article class="card" [appOpensEntry]="entry">
    <app-entry-actions [entry]="entry" [size]="size" [audio]="audio" [like]="likeShown" />
  </article>`,
  providers: [{ provide: EntryActionHandler, useExisting: forwardRef(() => HostComponent) }],
})
class HostComponent implements EntryActionHandler {
  entry: EntryDto = anEntry();
  size: 'sm' | 'md' = 'sm';
  audio = true;
  likeShown = true;
  cardOpened = false;
  favoriteCount = 0;
  kept: EntryDto | null = null;
  marked: EntryDto | null = null;
  liked: EntryDto | null = null;
  deleted: EntryDto | null = null;

  favorite(): void {
    this.favoriteCount++;
  }

  like(entry: EntryDto): void {
    this.liked = entry;
  }

  keep(entry: EntryDto): void {
    this.kept = entry;
  }

  toggleRead(entry: EntryDto): void {
    this.marked = entry;
  }

  open(): void {
    this.cardOpened = true;
  }

  deletePost(entry: EntryDto): void {
    this.deleted = entry;
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

function mount(testEntry: EntryDto = anEntry()) {
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
  anEntry({ title: 'Ep 1', attachments: [{ url: EPISODE, mimeType: 'audio/mpeg' }] });

describe('EntryActionsComponent', () => {
  let player: StubAudioPlayer;

  beforeEach(() => {
    player = new StubAudioPlayer();
    TestBed.configureTestingModule({
      imports: [HostComponent, provideTranslocoTesting()],
      providers: [
        { provide: AudioPlayerService, useValue: player.asService() },
        provideAccountIdentity(),
      ],
    });
  });

  it('renders the three actions with their labels', () => {
    const labels = buttons(mount()).map((button) => button.getAttribute('aria-label'));
    expect(labels).toEqual(['Favorite', 'Keep', 'Toggle read']);
  });

  it('reports each action state through aria-pressed', () => {
    // The third toggle is the tick: it reflects "viewed", not "read" (#482).
    const fixture = mount(anEntry({ isFavorite: true, isKept: false, isViewed: true }));
    const pressed = buttons(fixture).map((button) => button.getAttribute('aria-pressed'));
    expect(pressed).toEqual(['true', 'false', 'true']);
  });

  it('marks every active toggle the same way, the tick one included', () => {
    const fixture = mount(anEntry({ isFavorite: true, isKept: true, isViewed: true }));
    const on = buttons(fixture).map((button) => button.classList.contains('on'));
    expect(on).toEqual([true, true, true]);
  });

  it('leaves an inactive toggle unmarked', () => {
    const fixture = mount(anEntry({ isFavorite: false, isKept: false, isHidden: false }));
    const on = buttons(fixture).map((button) => button.classList.contains('on'));
    expect(on).toEqual([false, false, false]);
  });

  it('keeps one glyph per toggle, so only the colour moves', () => {
    // The read button swapped to an envelope once read; the state is carried by
    // the accent now, exactly as favorite and keep carry theirs (#435).
    for (const isHidden of [false, true]) {
      const text = mount(anEntry({ isHidden })).nativeElement.textContent;
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

    expect(fills(anEntry({ isFavorite: false, isKept: false }))).toEqual([false, false, false]);
    expect(fills(anEntry({ isFavorite: true, isKept: true, isViewed: true }))).toEqual([
      true,
      true,
      false,
    ]);
  });

  it('styles every toggle through the shared flag-toggle look', () => {
    const shared = buttons(mount()).map((button) => button.classList.contains('flag-toggle'));
    expect(shared).toEqual([true, true, true]);
  });

  describe('tooltips', () => {
    const titles = (testEntry: EntryDto) =>
      buttons(mount(testEntry)).map((button) => button.getAttribute('title'));

    it('says what each click will do while the toggles are off', () => {
      expect(titles(anEntry({ isFavorite: false, isKept: false, isViewed: false }))).toEqual([
        'Add to favourites',
        'Keep — never deleted when old entries are cleaned up',
        'Mark as read — adds it to Recently read',
      ]);
    });

    it('says what each click will undo while the toggles are on', () => {
      expect(titles(anEntry({ isFavorite: true, isKept: true, isViewed: true }))).toEqual([
        'Remove from favourites',
        'Stop keeping — old-entry cleanup may delete it again',
        'Remove from Recently read',
      ]);
    });

    it.each([
      ['bluesky', false, 'Like on Bluesky — a public like the author sees'],
      ['bluesky', true, 'Take back your like on Bluesky'],
      ['mastodon', false, 'Like on Mastodon — a public like the author sees'],
      ['mastodon', true, 'Take back your like on Mastodon'],
    ] as const)('words the %s like (liked: %s) for its network', (network, isLiked, title) => {
      const like = buttons(
        mount(anEntry({ social: { network, isLiked, canLike: true, isOwn: false } })),
      )[1];
      expect(like.getAttribute('title')).toBe(title);
    });

    it('translates them', () => {
      const fixture = mount(
        anEntry({ social: { network: 'bluesky', isLiked: false, canLike: true, isOwn: false } }),
      );
      const transloco = TestBed.inject(TranslocoService);
      transloco.setActiveLang('de');
      fixture.detectChanges();
      expect(buttons(fixture).map((button) => button.getAttribute('title'))).toEqual([
        'Zu Favoriten hinzufügen',
        'Auf Bluesky liken — ein öffentliches Like, das der Autor sieht',
        'Merken — wird beim Aufräumen alter Einträge nie gelöscht',
        'Als gelesen markieren — landet in „Zuletzt gelesen“',
      ]);
    });
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

    it('shows the declared duration left of play (#1449)', () => {
      const fixture = mount(
        anEntry({
          attachments: [{ url: EPISODE, mimeType: 'audio/mpeg', durationInSeconds: 3_725 }],
        }),
      );
      const duration = fixture.nativeElement.querySelector('.duration');
      expect(duration.textContent.trim()).toBe('1:02:05');
      expect(duration.nextElementSibling.getAttribute('aria-label')).toBe('Play');
    });

    it('shows no duration when the feed declares none (#1449)', () => {
      expect(mount(episode()).nativeElement.querySelector('.duration')).toBeNull();
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

  describe('on a social post (#1600)', () => {
    const blueskyPost = (isLiked = false): EntryDto =>
      anEntry({ social: { network: 'bluesky', isLiked, canLike: true, isOwn: false } });

    it('offers the like right after favorite, named for the network', () => {
      const labels = buttons(mount(blueskyPost())).map((button) =>
        button.getAttribute('aria-label'),
      );
      expect(labels).toEqual(['Favorite', 'Like on Bluesky', 'Keep', 'Toggle read']);
    });

    it('names the undo once liked, pressed and filled', () => {
      const fixture = mount(
        anEntry({ social: { network: 'mastodon', isLiked: true, canLike: true, isOwn: false } }),
      );
      const like = buttons(fixture)[1];
      expect(like.getAttribute('aria-label')).toBe('Unlike on Mastodon');
      expect(like.getAttribute('aria-pressed')).toBe('true');
      expect(like.textContent).toContain('thumb_up');
      expect(
        fixture.debugElement.queryAll(By.directive(IconComponent))[1].componentInstance.fill(),
      ).toBe(true);
    });

    it('likes without opening the card', () => {
      const fixture = mount(blueskyPost());

      buttons(fixture)[1].click();

      expect(fixture.componentInstance.liked).toBe(fixture.componentInstance.entry);
      expect(fixture.componentInstance.cardOpened).toBe(false);
    });

    it('offers no like on an entry that is no social post', () => {
      expect(mount().nativeElement.textContent).not.toContain('thumb_up');
    });

    it('leaves the like to the article view’s counts row when told to (#1615)', () => {
      const fixture = TestBed.createComponent(HostComponent);
      fixture.componentInstance.entry = blueskyPost();
      fixture.componentInstance.likeShown = false;
      fixture.detectChanges();

      expect(fixture.nativeElement.textContent).not.toContain('thumb_up');
    });

    it('offers no like on a post the user has no account to like from (#1615)', () => {
      const post = anEntry({
        social: { network: 'mastodon', isLiked: false, canLike: false, isOwn: false },
      });

      expect(mount(post).nativeElement.textContent).not.toContain('thumb_up');
    });
  });

  it('offers Delete post last, and only on my own post', () => {
    const own = anEntry({
      social: { network: 'mastodon', isLiked: false, canLike: true, isOwn: true },
    });
    const foreign = anEntry({
      social: { network: 'mastodon', isLiked: false, canLike: true, isOwn: false },
    });

    const ownLabels = buttons(mount(own)).map((button) => button.getAttribute('aria-label'));
    const foreignLabels = buttons(mount(foreign)).map((button) =>
      button.getAttribute('aria-label'),
    );

    expect(ownLabels.at(-1)).toBe('Delete post');
    expect(foreignLabels).not.toContain('Delete post');
  });

  it('deletes through the handler without opening the card', () => {
    const own = anEntry({
      social: { network: 'bluesky', isLiked: false, canLike: true, isOwn: true },
    });
    const fixture = mount(own);

    buttons(fixture).at(-1)?.click();

    expect(fixture.componentInstance.deleted).toBe(own);
    expect(fixture.componentInstance.cardOpened).toBe(false);
  });
});
