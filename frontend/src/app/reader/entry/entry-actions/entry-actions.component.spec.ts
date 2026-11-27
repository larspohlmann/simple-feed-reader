import { Component, forwardRef } from '@angular/core';
import { EMPTY, Observable, Subject } from 'rxjs';
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
import { LabelMenu } from '../../labels/label-menu.service';

/** Stand-in for the card, wired like the real ones: proves an action never reaches it. */
@Component({
  imports: [EntryActionsComponent, OpensEntryDirective],
  template: `<article class="card" [appOpensEntry]="entry">
    <app-entry-actions
      [entry]="entry"
      [size]="size"
      [audio]="audio"
      [like]="likeShown"
      [labelMenu]="labelMenu"
    />
  </article>`,
  providers: [{ provide: EntryActionHandler, useExisting: forwardRef(() => HostComponent) }],
})
class HostComponent implements EntryActionHandler {
  entry: EntryDto = anEntry();
  size: 'sm' | 'md' = 'sm';
  audio = true;
  likeShown = false;
  labelMenu: 'list' | 'article' = 'list';
  likeAnswer = new Subject<void>();
  cardOpened = false;
  favoriteCount = 0;
  kept: EntryDto | null = null;
  unshared: EntryDto | null = null;
  marked: EntryDto | null = null;
  liked: EntryDto | null = null;
  deleted: EntryDto | null = null;

  readonly vote = (): Observable<void> => EMPTY;
  readonly attachLabel = (): void => undefined;
  readonly detachLabel = (): void => undefined;
  readonly createLabel = (): Observable<void> => EMPTY;

  favorite(): void {
    this.favoriteCount++;
  }

  like(entry: EntryDto): Observable<void> {
    this.liked = entry;
    return this.likeAnswer;
  }

  keep(entry: EntryDto): void {
    this.kept = entry;
  }

  unshare(entry: EntryDto): void {
    this.unshared = entry;
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

function mountLiking(testEntry: EntryDto) {
  const fixture = TestBed.createComponent(HostComponent);
  fixture.componentInstance.entry = testEntry;
  fixture.componentInstance.likeShown = true;
  fixture.detectChanges();
  return fixture;
}

const buttons = (fixture: { nativeElement: HTMLElement }) =>
  Array.from(fixture.nativeElement.querySelectorAll('button'));

/** The flag toggles alone: a favorite or kept entry also shows the label button, which opens a menu. */
const toggles = (fixture: { nativeElement: HTMLElement }) =>
  buttons(fixture).filter((button) => button.getAttribute('aria-haspopup') !== 'menu');

const iconSizes = (fixture: ReturnType<typeof mount>) =>
  fixture.debugElement
    .queryAll(By.directive(IconComponent))
    .map((icon) => icon.componentInstance.size());

const EPISODE = 'https://x.test/ep.mp3';
const episode = (): EntryDto =>
  anEntry({ title: 'Ep 1', attachments: [{ url: EPISODE, mimeType: 'audio/mpeg' }] });

describe('EntryActionsComponent', () => {
  let player: StubAudioPlayer;
  let labelMenu: { open: jest.Mock; isOpenAt: jest.Mock; closeWithin: jest.Mock };

  beforeEach(() => {
    player = new StubAudioPlayer();
    labelMenu = { open: jest.fn(), isOpenAt: jest.fn(() => false), closeWithin: jest.fn() };
    TestBed.configureTestingModule({
      imports: [HostComponent, provideTranslocoTesting()],
      providers: [
        { provide: AudioPlayerService, useValue: player.asService() },
        { provide: LabelMenu, useValue: labelMenu },
        provideAccountIdentity(),
      ],
    });
  });

  describe('the label button (#2581)', () => {
    const labels = (fixture: ReturnType<typeof mount>) =>
      buttons(fixture).map((button) => button.getAttribute('aria-label'));

    it('leads the star on every favorite row', () => {
      expect(labels(mount(anEntry({ isFavorite: true })))).toEqual([
        'Labels',
        'Favorite',
        'Keep',
        'Toggle read',
      ]);
    });

    it('leads the star on every kept row', () => {
      expect(labels(mount(anEntry({ isKept: true })))[0]).toBe('Labels');
    });

    it('is absent from a row that is neither favorite nor kept', () => {
      expect(labels(mount(anEntry({ isFavorite: false, isKept: false })))).not.toContain('Labels');
    });

    it.each([
      ['favorite', { isFavorite: true }],
      ['kept', { isKept: true }],
    ] as const)('appears on a row the moment it turns %s, and leaves again', (_, flag) => {
      const fixture = mount(anEntry({ isFavorite: false, isKept: false }));
      expect(labels(fixture)).not.toContain('Labels');

      fixture.componentInstance.entry = { ...fixture.componentInstance.entry, ...flag };
      fixture.detectChanges();
      expect(labels(fixture)[0]).toBe('Labels');

      fixture.componentInstance.entry = anEntry({ isFavorite: false, isKept: false });
      fixture.detectChanges();
      expect(labels(fixture)).not.toContain('Labels');
    });

    it('follows the tick in the article header of a kept entry', () => {
      const fixture = TestBed.createComponent(HostComponent);
      fixture.componentInstance.entry = anEntry({ isKept: true });
      fixture.componentInstance.labelMenu = 'article';
      fixture.detectChanges();

      expect(labels(fixture)).toEqual(['Favorite', 'Keep', 'Toggle read', 'Labels']);
    });

    it('appears in the article header once the entry turns favorite', () => {
      const fixture = TestBed.createComponent(HostComponent);
      fixture.componentInstance.entry = anEntry({ isFavorite: false, isKept: false });
      fixture.componentInstance.labelMenu = 'article';
      fixture.detectChanges();
      expect(labels(fixture)).not.toContain('Labels');

      fixture.componentInstance.entry = anEntry({ isFavorite: true });
      fixture.detectChanges();

      expect(labels(fixture)).toContain('Labels');
    });

    it('fills its glyph only while the entry carries a label', () => {
      const fill = (testEntry: EntryDto) =>
        mount(testEntry)
          .debugElement.queryAll(By.directive(IconComponent))
          .find((icon) => icon.componentInstance.name() === 'label')!
          .componentInstance.fill();

      expect(fill(anEntry({ isFavorite: true, labels: [] }))).toBe(false);
      expect(fill(anEntry({ isFavorite: true, labels: [{ id: 1, name: 'Work' }] }))).toBe(true);
    });

    it('is a menu button that says whether its menu is open, never a pressed toggle', () => {
      const fixture = mount(anEntry({ isFavorite: true, labels: [{ id: 1, name: 'Work' }] }));
      const [button] = buttons(fixture);
      expect(button.getAttribute('aria-haspopup')).toBe('menu');
      expect(button.getAttribute('aria-expanded')).toBe('false');
      expect(button.hasAttribute('aria-pressed')).toBe(false);
      expect(button.classList).toContain('flag-toggle');
      expect(button.classList).toContain('on');

      labelMenu.isOpenAt.mockImplementation((anchor: HTMLElement) => anchor === button);
      fixture.componentInstance.entry = { ...fixture.componentInstance.entry };
      fixture.detectChanges();

      expect(button.getAttribute('aria-expanded')).toBe('true');
    });

    it('closes its menu when the row leaves the page', () => {
      const fixture = mount(anEntry({ isFavorite: true }));
      const host = fixture.nativeElement.querySelector('app-entry-actions');

      fixture.destroy();

      expect(labelMenu.closeWithin).toHaveBeenCalledWith(host);
    });

    it('reads no list on screen, so a Trending story’s rows follow the same rule', () => {
      expect(() => mount(anEntry())).not.toThrow();
      expect(labels(mount(anEntry({ isKept: true })))).toContain('Labels');
    });

    it('opens the menu at itself with the handler, by click, Enter or Space, without opening the card', () => {
      const fixture = mount(anEntry({ isFavorite: true }));
      const [button] = buttons(fixture);

      button.click();
      pressEnter(button);
      pressSpace(button);

      expect(labelMenu.open).toHaveBeenCalledTimes(3);
      expect(labelMenu.open).toHaveBeenCalledWith(
        fixture.componentInstance.entry,
        button,
        fixture.componentInstance,
      );
      expect(button.getAttribute('title')).toBe('Labels');
      expect(fixture.componentInstance.cardOpened).toBe(false);
    });
  });

  it('offers Remove from shared only on a shared entry', () => {
    const label = 'Remove from shared';
    const offered = (fixture: ReturnType<typeof mount>) =>
      buttons(fixture).find((button) => button.getAttribute('aria-label') === label);
    expect(offered(mount(anEntry({ isShared: false })))).toBeUndefined();

    const shared = anEntry({ isShared: true });
    const fixture = mount(shared);
    offered(fixture)!.click();

    expect(fixture.componentInstance.unshared).toBe(shared);
    expect(fixture.componentInstance.cardOpened).toBe(false);
  });

  it('renders the three actions with their labels', () => {
    const labels = buttons(mount()).map((button) => button.getAttribute('aria-label'));
    expect(labels).toEqual(['Favorite', 'Keep', 'Toggle read']);
  });

  it('reports each action state through aria-pressed', () => {
    // The third toggle is the tick: it reflects "viewed", not "read" (#482).
    const fixture = mount(anEntry({ isFavorite: true, isKept: false, isViewed: true }));
    const pressed = toggles(fixture).map((button) => button.getAttribute('aria-pressed'));
    expect(pressed).toEqual(['true', 'false', 'true']);
  });

  it('marks every active toggle the same way, the tick one included', () => {
    const fixture = mount(anEntry({ isFavorite: true, isKept: true, isViewed: true }));
    const on = toggles(fixture).map((button) => button.classList.contains('on'));
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
        .filter((icon) => icon.componentInstance.name() !== 'label')
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
      toggles(mount(testEntry)).map((button) => button.getAttribute('title'));

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
        mountLiking(anEntry({ social: { network, isLiked, canLike: true, isOwn: false } })),
      )[1];
      expect(like.getAttribute('title')).toBe(title);
    });

    it('translates them', () => {
      const fixture = mountLiking(
        anEntry({
          social: {
            network: 'bluesky',
            isLiked: false,
            canLike: true,
            isOwn: false,
          },
        }),
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
      anEntry({
        social: { network: 'bluesky', isLiked, canLike: true, isOwn: false },
      });

    it('offers the like right after favorite, named for the network', () => {
      const labels = buttons(mountLiking(blueskyPost())).map((button) =>
        button.getAttribute('aria-label'),
      );
      expect(labels).toEqual(['Favorite', 'Like on Bluesky', 'Keep', 'Toggle read']);
    });

    it('names the undo once liked, pressed and filled', () => {
      const fixture = mountLiking(
        anEntry({
          social: {
            network: 'mastodon',
            isLiked: true,
            canLike: true,
            isOwn: false,
          },
        }),
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
      const fixture = mountLiking(blueskyPost());

      buttons(fixture)[1].click();

      expect(fixture.componentInstance.liked).toBe(fixture.componentInstance.entry);
      expect(fixture.componentInstance.cardOpened).toBe(false);
    });

    it('shows the like count on the like button', () => {
      const like = buttons(mountLiking({ ...blueskyPost(), likeCount: 47 }))[1];
      expect(like.querySelector('.count')?.textContent?.trim()).toBe('47');
      expect(like.getAttribute('aria-label')).toBe('Like on Bluesky, 47 likes');
    });

    it('shows a zero like count on the like button', () => {
      const like = buttons(mountLiking({ ...blueskyPost(), likeCount: 0 }))[1];
      expect(like.querySelector('.count')?.textContent?.trim()).toBe('0');
    });

    it('shows the pending dots in place of the thumb until the like settles', () => {
      const fixture = mountLiking({ ...blueskyPost(), likeCount: 4 });
      const like = () => buttons(fixture)[1];

      like().click();
      fixture.detectChanges();

      expect(like().querySelector('app-pending-dots')).not.toBeNull();
      expect(like().textContent).not.toContain('thumb_up');
      expect(like().getAttribute('aria-busy')).toBe('true');
      expect(like().querySelector('.count')?.textContent?.trim()).toBe('4');
      fixture.componentInstance.likeAnswer.complete();
      fixture.detectChanges();
      expect(like().querySelector('app-pending-dots')).toBeNull();
      expect(like().textContent).toContain('thumb_up');
      expect(like().getAttribute('aria-busy')).toBeNull();
    });

    it('drops the dots when the like fails and sends nothing while pending', () => {
      const fixture = mountLiking(blueskyPost());
      const like = () => buttons(fixture)[1];
      const sent = jest.spyOn(fixture.componentInstance, 'like');

      like().click();
      like().click();
      expect(sent).toHaveBeenCalledTimes(1);
      fixture.componentInstance.likeAnswer.error(new Error('no'));
      fixture.detectChanges();
      expect(like().querySelector('app-pending-dots')).toBeNull();
    });

    it('shows no number on the like button while the count is unknown', () => {
      const like = buttons(mountLiking(blueskyPost()))[1];
      expect(like.querySelector('.count')).toBeNull();
      expect(like.getAttribute('aria-label')).toBe('Like on Bluesky');
    });

    it('keeps a known count beside the local toggle on a post the user cannot like on its network', () => {
      const post = anEntry({
        likeCount: 1,
        social: { network: 'mastodon', isLiked: false, canLike: false, isOwn: false },
      });
      const like = buttons(mountLiking(post))[1];

      expect(like.getAttribute('aria-label')).toBe('Like, 1 like');
      expect(like.querySelector('.count')?.textContent?.trim()).toBe('1');
    });

    it('offers the local like on an entry that is no social post', () => {
      const fixture = mountLiking(anEntry({ social: null, myVote: null }));

      expect(buttons(fixture).map((button) => button.getAttribute('aria-label'))).toEqual([
        'Favorite',
        'Like',
        'Keep',
        'Toggle read',
      ]);
    });

    it('offers no like on a Lemmy post the reader voted on', () => {
      expect(
        mountLiking(anEntry({ social: null, myVote: 1 })).nativeElement.textContent,
      ).not.toContain('thumb_up');
    });

    it('leaves the like to the article view’s counts row when told to (#1615)', () => {
      const fixture = TestBed.createComponent(HostComponent);
      fixture.componentInstance.entry = blueskyPost();
      fixture.componentInstance.likeShown = false;
      fixture.detectChanges();

      expect(fixture.nativeElement.textContent).not.toContain('thumb_up');
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
