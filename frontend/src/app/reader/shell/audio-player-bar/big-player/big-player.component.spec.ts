import { WritableSignal, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { CdkScrollable } from '@angular/cdk/scrolling';
import { Router, provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../../../../testing/transloco-testing';
import { bigPlayerAudioSignals } from '../../../../../testing/big-player-audio-signals';
import { AudioPlayerService, AudioTrack, SKIP_SECONDS } from '../../../audio-player.service';
import { LayoutService } from '../../../layout.service';
import type { ChapterDto } from '../../../models';
import { scrubberInput } from '../../../../../testing/scrubber-testing';
import { BigPlayerComponent } from './big-player.component';

const first: AudioTrack = {
  url: 'https://x.test/one.mp3',
  title: 'Episode 1',
  faviconUrl: null,
  imageUrl: null,
  durationInSeconds: 300,
};

const episode: AudioTrack = {
  url: 'https://x.test/two.mp3',
  title: 'Episode 2',
  faviconUrl: null,
  imageUrl: null,
  durationInSeconds: 600,
  entry: {
    id: 42,
    title: 'Episode 2: the headline',
    feedTitle: 'Fixture podcast',
    publishedAt: '2026-09-01T08:00:00Z',
    excerpt: 'What this episode is about.',
  },
};

function stub() {
  return {
    ...bigPlayerAudioSignals(),
    toggle: jest.fn(),
    seek: jest.fn(),
    skip: jest.fn(),
    previous: jest.fn(),
    next: jest.fn(),
    previousChapter: jest.fn(),
    nextChapter: jest.fn(),
    playChapter: jest.fn(),
    cycleRate: jest.fn(),
  };
}

let player: ReturnType<typeof stub>;
let ref: { close: jest.Mock };
let layout: { isPhone: WritableSignal<boolean> };
let playlistShown: WritableSignal<boolean>;

function render(current: AudioTrack | null = episode, phone = false) {
  player = stub();
  ref = { close: jest.fn() };
  layout = { isPhone: signal(phone) };
  playlistShown = signal(false);
  player.tracks.set([first, episode]);
  player.index.set(1);
  player.current.set(current);
  player.duration.set(600);
  player.position.set(90);
  TestBed.configureTestingModule({
    imports: [BigPlayerComponent, provideTranslocoTesting()],
    providers: [
      provideRouter([]),
      { provide: AudioPlayerService, useValue: player },
      { provide: DialogRef, useValue: ref },
      { provide: DIALOG_DATA, useValue: { playlistShown } },
      { provide: LayoutService, useValue: layout },
    ],
  });
  const fixture = TestBed.createComponent(BigPlayerComponent);
  fixture.detectChanges();
  return fixture;
}

function query<T extends HTMLElement>(
  fixture: ReturnType<typeof render>,
  selector: string,
): T | null {
  return fixture.debugElement.query(By.css(selector))?.nativeElement ?? null;
}

function touch(type: string, target: EventTarget, ...clientYs: number[]) {
  const event = new Event(type, { bubbles: true });
  const points = clientYs.map((clientY) => ({ clientY }));
  Object.defineProperty(event, type === 'touchend' ? 'changedTouches' : 'touches', {
    value: points,
  });
  target.dispatchEvent(event);
}

function pull(target: EventTarget, [from, to]: [number, number]) {
  touch('touchstart', target, from);
  touch('touchend', target, to);
}

describe('BigPlayerComponent', () => {
  beforeEach(() => {
    Element.prototype.scrollIntoView = jest.fn();
  });

  afterEach(() => {
    jest.restoreAllMocks();
    delete (Element.prototype as Partial<Element>).scrollIntoView;
  });

  it('shows the title, the feed line, the excerpt and the queue position', () => {
    const fixture = render();

    expect(query(fixture, '.title')!.textContent).toContain('Episode 2');
    expect(query(fixture, '.source')!.textContent).toContain('Fixture podcast · ');
    expect(query(fixture, '.excerpt')!.textContent).toContain('What this episode is about.');
    expect(query(fixture, '.position')!.textContent!.trim()).toBe('2 of 2');
  });

  it('drives the scrubber, with elapsed and remaining time', () => {
    const fixture = render();

    expect(scrubberInput(fixture).max).toBe('600');
    expect(scrubberInput(fixture).value).toBe('90');
    expect(scrubberInput(fixture).style.getPropertyValue('--played')).toBe('0.15');
    const times = fixture.debugElement.queryAll(By.css('.time')).map((time) => time.nativeElement);
    expect(times.map((time) => time.textContent.trim())).toEqual(['1:30', '−8:30']);

    scrubberInput(fixture).value = '75';
    scrubberInput(fixture).dispatchEvent(new Event('input'));
    expect(player.seek).toHaveBeenCalledWith(75);
  });

  it('marks the chapters of the track on the scrubber', () => {
    const fixture = render();
    player.chapters.set([
      { startSeconds: 0, title: 'Intro' },
      { startSeconds: 90, title: 'Main' },
      { startSeconds: 300, title: 'Outro' },
    ]);
    fixture.detectChanges();

    const marks = fixture.debugElement.queryAll(By.css('.mark')).map((mark) => mark.nativeElement);
    expect(marks.map((mark: HTMLElement) => mark.style.getPropertyValue('--at'))).toEqual([
      '0.15',
      '0.5',
    ]);
  });

  it('thickens the scrubber while it is held', () => {
    const fixture = render();

    scrubberInput(fixture).dispatchEvent(new Event('pointerdown'));
    fixture.detectChanges();
    expect(query(fixture, '.scrub')!.classList).toContain('dragging');

    scrubberInput(fixture).dispatchEvent(new Event('pointerup'));
    fixture.detectChanges();
    expect(query(fixture, '.scrub')!.classList).not.toContain('dragging');
  });

  it('drives the transport controls', () => {
    const fixture = render();
    player.hasNext.set(true);
    fixture.detectChanges();

    query(fixture, '.play')!.click();
    query(fixture, '.back')!.click();
    query(fixture, '.forward')!.click();
    query(fixture, '.previous')!.click();
    query(fixture, '.next')!.click();

    expect(player.toggle).toHaveBeenCalled();
    expect(player.skip).toHaveBeenNthCalledWith(1, -SKIP_SECONDS);
    expect(player.skip).toHaveBeenNthCalledWith(2, SKIP_SECONDS);
    expect(player.previous).toHaveBeenCalled();
    expect(player.next).toHaveBeenCalled();
  });

  it('shows the speed and steps it', () => {
    const fixture = render();
    const speed = query(fixture, '.speed')!;

    expect(speed.textContent!.trim()).toBe('1×');
    speed.click();
    expect(player.cycleRate).toHaveBeenCalled();

    player.rate.set(1.5);
    fixture.detectChanges();
    expect(speed.textContent!.trim()).toBe('1.5×');
    expect(speed.getAttribute('aria-label')).toBe('Playback speed 1.5×');
  });

  it('minimises without a result', () => {
    const fixture = render();

    query(fixture, '.collapse')!.click();

    expect(ref.close).toHaveBeenCalledWith();
  });

  it('hands over to the playlist panel', () => {
    const fixture = render();

    query(fixture, '.show-playlist')!.click();

    expect(ref.close).toHaveBeenCalledWith('playlist');
  });

  describe('in the phone sheet', () => {
    it('shows and hides the playlist under the controls instead of handing over', () => {
      const fixture = render(episode, true);
      const button = query<HTMLButtonElement>(fixture, '.show-playlist')!;
      expect(button.getAttribute('aria-expanded')).toBe('false');

      button.click();
      fixture.detectChanges();
      expect(ref.close).not.toHaveBeenCalled();
      expect(playlistShown()).toBe(true);
      expect(button.getAttribute('aria-expanded')).toBe('true');
      expect(button.getAttribute('aria-controls')).toBe('big-player-playlist');
      expect(fixture.debugElement.queryAll(By.css('#big-player-playlist .row'))).toHaveLength(2);

      button.click();
      fixture.detectChanges();
      expect(playlistShown()).toBe(false);
      expect(query(fixture, 'app-audio-playlist')).toBeNull();
    });

    it('scrolls the bottom row to the top when the playlist appears, not when it goes', () => {
      const fixture = render(episode, true);
      const scrollIntoView = Element.prototype.scrollIntoView as jest.Mock;

      query(fixture, '.show-playlist')!.click();
      fixture.detectChanges();
      expect(scrollIntoView).toHaveBeenCalledTimes(1);
      expect(scrollIntoView).toHaveBeenCalledWith({ block: 'start', behavior: 'smooth' });
      expect(scrollIntoView.mock.contexts[0]).toBe(query(fixture, '.extras'));

      query(fixture, '.show-playlist')!.click();
      fixture.detectChanges();
      expect(scrollIntoView).toHaveBeenCalledTimes(1);
    });

    it('reveals the list without animation when the reader asks for reduced motion', () => {
      const fixture = render(episode, true);
      jest.spyOn(window, 'matchMedia').mockReturnValue({ matches: true } as MediaQueryList);

      query(fixture, '.show-playlist')!.click();
      fixture.detectChanges();

      expect(Element.prototype.scrollIntoView).toHaveBeenCalledWith({
        block: 'start',
        behavior: 'auto',
      });
    });

    it('opens with the playlist it was last left with', () => {
      const fixture = render(episode, true);
      playlistShown.set(true);
      fixture.detectChanges();

      expect(query(fixture, 'app-audio-playlist')).not.toBeNull();
    });
  });

  describe('the chapters', () => {
    const chapters: ChapterDto[] = [
      { startSeconds: 0, title: 'Intro' },
      { startSeconds: 90, title: 'Main' },
      { startSeconds: 300, title: 'Outro' },
    ];

    function chaptered(phone = false) {
      const fixture = render(episode, phone);
      player.chapters.set(chapters);
      player.chapterIndex.set(1);
      player.currentChapter.set(chapters[1]);
      player.chapterProgress.set({ fraction: 0.5, secondsLeft: 105 });
      player.hasPreviousChapter.set(true);
      player.hasNextChapter.set(true);
      fixture.detectChanges();
      return fixture;
    }

    function chapteredAlone(phone = false) {
      const fixture = chaptered(phone);
      player.tracks.set([episode]);
      player.index.set(0);
      fixture.detectChanges();
      return fixture;
    }

    function control(fixture: ReturnType<typeof render>, selector: string): HTMLButtonElement {
      return query<HTMLButtonElement>(fixture, selector)!;
    }

    function listRows(fixture: ReturnType<typeof render>): HTMLButtonElement[] {
      return fixture.debugElement
        .queryAll(By.css('#big-player-chapters .reader-chapters__row'))
        .map((row) => row.nativeElement);
    }

    it('shows neither the strip nor the list for a track without chapters', () => {
      const fixture = render();

      expect(query(fixture, '.chapter-strip')).toBeNull();
      expect(query(fixture, '.chapters')).toBeNull();
      expect(query(fixture, 'app-audio-chapters')).toBeNull();
    });

    it('names the current chapter with its position and time left under the transport', () => {
      const fixture = chaptered();

      expect(query(fixture, '.transport + .chapter-strip')).not.toBeNull();
      expect(query(fixture, '.chapter-name')!.textContent!.trim()).toBe('Main');
      expect(query(fixture, '.chapter-meta')!.textContent!.replace(/\s+/g, ' ').trim()).toBe(
        'Chapter 2 of 3 schedule 1:45',
      );
    });

    it('leaves the time left out while the chapter end is unknown', () => {
      const fixture = chaptered();
      player.chapterProgress.set(null);
      fixture.detectChanges();

      expect(query(fixture, '.chapter-meta')!.textContent!.replace(/\s+/g, ' ').trim()).toBe(
        'Chapter 2 of 3',
      );
      expect(query(fixture, '.chapter-left')).toBeNull();
    });

    it('offers the chapter count where the title goes before the first chapter starts', () => {
      const fixture = chaptered();
      player.chapterIndex.set(-1);
      player.currentChapter.set(null);
      player.chapterProgress.set(null);
      fixture.detectChanges();

      expect(query(fixture, '.chapter-name')!.textContent!.trim()).toBe('Chapters (3)');
      expect(query(fixture, '.chapter-meta')).toBeNull();
    });

    it('steps between chapters, leaving previous and next to the tracks', () => {
      const fixture = chaptered();

      control(fixture, '.previous-chapter').click();
      control(fixture, '.next-chapter').click();

      expect(player.previousChapter).toHaveBeenCalledTimes(1);
      expect(player.nextChapter).toHaveBeenCalledTimes(1);
      expect(player.previous).not.toHaveBeenCalled();
      expect(player.next).not.toHaveBeenCalled();
      expect(control(fixture, '.previous-chapter').getAttribute('aria-label')).toBe(
        'Previous chapter',
      );
      expect(control(fixture, '.next-chapter').getAttribute('aria-label')).toBe('Next chapter');
    });

    it('disables the chapter steps at the ends of the track', () => {
      const fixture = chaptered();
      player.hasPreviousChapter.set(false);
      fixture.detectChanges();

      expect(control(fixture, '.previous-chapter').disabled).toBe(true);
      expect(control(fixture, '.next-chapter').disabled).toBe(false);

      player.hasPreviousChapter.set(true);
      player.hasNextChapter.set(false);
      fixture.detectChanges();

      expect(control(fixture, '.previous-chapter').disabled).toBe(false);
      expect(control(fixture, '.next-chapter').disabled).toBe(true);
    });

    it('opens the only queued track with its list under a counted heading, between the extras and the queue', () => {
      const fixture = chapteredAlone(true);
      playlistShown.set(true);
      fixture.detectChanges();

      const heading = control(fixture, '.chapters-heading');
      expect(heading.textContent!.replace(/\s+/g, ' ').trim()).toBe('expand_more Chapters (3)');
      expect(heading.getAttribute('aria-expanded')).toBe('true');
      expect(heading.getAttribute('aria-controls')).toBe('big-player-chapters');
      expect(query(fixture, '.now-playing + .chapters')).not.toBeNull();
      expect(query(fixture, '.chapters + .queue')).not.toBeNull();
    });

    it('keeps the list closed while the queue holds more tracks', () => {
      const fixture = chaptered();

      const heading = control(fixture, '.chapters-heading');
      expect(heading.textContent).toContain('chevron_right');
      expect(heading.getAttribute('aria-expanded')).toBe('false');
      expect(heading.hasAttribute('aria-controls')).toBe(false);
      expect(control(fixture, '.chapter-title').getAttribute('aria-expanded')).toBe('false');
      expect(query(fixture, 'app-audio-chapters')).toBeNull();
    });

    it('closes the list when a second track joins the queue', () => {
      const fixture = chapteredAlone();

      player.tracks.set([first, episode]);
      fixture.detectChanges();

      expect(query(fixture, 'app-audio-chapters')).toBeNull();
    });

    it('hands the list the chapters, the current one and its progress', () => {
      const fixture = chapteredAlone();

      expect(listRows(fixture).map((row) => row.textContent!.replace(/\s+/g, ''))).toEqual([
        '0:00Intro',
        '1:30Mainschedule1:45',
        '5:00Outro',
      ]);
      expect(listRows(fixture)[1].getAttribute('aria-current')).toBe('true');
      expect(listRows(fixture)[1].style.getPropertyValue('--chapter-progress')).toBe('0.5');
    });

    it('plays a tapped chapter', () => {
      const fixture = chapteredAlone();

      listRows(fixture)[2].click();

      expect(player.playChapter).toHaveBeenCalledWith(2);
    });

    for (const toggle of ['.chapter-title', '.chapters-heading']) {
      it(`collapses and reopens the list from ${toggle}`, () => {
        const fixture = chapteredAlone();

        control(fixture, toggle).click();
        fixture.detectChanges();
        expect(query(fixture, 'app-audio-chapters')).toBeNull();
        expect(control(fixture, '.chapter-title').getAttribute('aria-expanded')).toBe('false');
        expect(control(fixture, '.chapters-heading').getAttribute('aria-expanded')).toBe('false');
        expect(control(fixture, '.chapters-heading').hasAttribute('aria-controls')).toBe(false);
        expect(control(fixture, '.chapters-heading').textContent).toContain('chevron_right');

        control(fixture, toggle).click();
        fixture.detectChanges();
        expect(listRows(fixture)).toHaveLength(3);
        expect(control(fixture, '.chapter-title').getAttribute('aria-expanded')).toBe('true');
      });
    }

    it('scrolls the list into view when the sheet reopens it, not when it closes', () => {
      const fixture = chapteredAlone(true);
      const scrollIntoView = Element.prototype.scrollIntoView as jest.Mock;

      control(fixture, '.chapter-title').click();
      fixture.detectChanges();
      expect(scrollIntoView).not.toHaveBeenCalled();

      control(fixture, '.chapter-title').click();
      fixture.detectChanges();
      expect(scrollIntoView).toHaveBeenCalledTimes(1);
      expect(scrollIntoView).toHaveBeenCalledWith({ block: 'start', behavior: 'smooth' });
      expect(scrollIntoView.mock.contexts[0]).toBe(query(fixture, '.chapters'));
    });

    it('shows the reopened list in place on the card', () => {
      const fixture = chapteredAlone();

      control(fixture, '.chapters-heading').click();
      fixture.detectChanges();
      control(fixture, '.chapters-heading').click();
      fixture.detectChanges();

      expect(listRows(fixture)).toHaveLength(3);
      expect(Element.prototype.scrollIntoView).not.toHaveBeenCalled();
    });
  });

  it('keeps the card free of the playlist, even when the sheet last showed it', () => {
    const fixture = render();
    playlistShown.set(true);
    fixture.detectChanges();

    expect(query(fixture, 'app-audio-playlist')).toBeNull();
    expect(query(fixture, '.show-playlist')!.hasAttribute('aria-expanded')).toBe(false);
  });

  it('is the scroll container a dragged row scrolls', () => {
    const fixture = render();

    expect(fixture.debugElement.injector.get(CdkScrollable, null)).not.toBeNull();
  });

  for (const control of ['.open-article', '.title-link']) {
    it(`opens the article from ${control}, keeping the list it was played from`, async () => {
      const fixture = render();
      const router = TestBed.inject(Router);
      await router.navigateByUrl('/?subscription=1');

      query(fixture, control)!.click();
      await fixture.whenStable();

      expect(router.url).toBe('/?subscription=1&entry=42-episode-2-the-headline');
      expect(ref.close).toHaveBeenCalledWith();
    });
  }

  it('drops a link card layered over the list when it opens the article', async () => {
    const fixture = render();
    const router = TestBed.inject(Router);
    await router.navigateByUrl('/?subscription=1&link=7.1');

    query(fixture, '.open-article')!.click();
    await fixture.whenStable();

    expect(router.url).toBe('/?subscription=1&entry=42-episode-2-the-headline');
  });

  it('leaves out the details a track saved before #1442 does not carry', () => {
    const fixture = render(first);

    expect(query(fixture, '.title')!.textContent).toContain('Episode 1');
    expect(query(fixture, '.source')).toBeNull();
    expect(query(fixture, '.excerpt')).toBeNull();
    expect(query(fixture, '.open-article')).toBeNull();
    expect(query(fixture, '.title-link')).toBeNull();
  });

  it('closes itself once nothing is loaded', () => {
    const fixture = render();

    player.current.set(null);
    fixture.detectChanges();

    expect(ref.close).toHaveBeenCalled();
  });

  describe('swipe down', () => {
    it('closes on a decisive downward pull', () => {
      const fixture = render();

      pull(fixture.nativeElement, [100, 180]);

      expect(ref.close).toHaveBeenCalledWith();
    });

    it('ignores a short pull', () => {
      const fixture = render();

      pull(fixture.nativeElement, [100, 140]);

      expect(ref.close).not.toHaveBeenCalled();
    });

    it('ignores a pull that starts on the scrubber', () => {
      const fixture = render();

      pull(scrubberInput(fixture), [100, 300]);

      expect(ref.close).not.toHaveBeenCalled();
    });

    it('ignores a pull that starts in the playlist', () => {
      const fixture = render(episode, true);
      query(fixture, '.show-playlist')!.click();
      fixture.detectChanges();

      pull(query(fixture, '#big-player-playlist .row')!, [100, 300]);

      expect(ref.close).not.toHaveBeenCalled();
    });

    it('ignores a pull while the sheet is scrolled', () => {
      const fixture = render();
      Object.defineProperty(fixture.nativeElement, 'scrollTop', { value: 10 });

      pull(fixture.nativeElement, [100, 300]);

      expect(ref.close).not.toHaveBeenCalled();
    });

    it('ignores a pull a second finger joins', () => {
      const fixture = render();
      const sheet = fixture.nativeElement as HTMLElement;

      touch('touchstart', sheet, 100);
      touch('touchstart', sheet, 100, 100);
      touch('touchend', sheet, 300);

      expect(ref.close).not.toHaveBeenCalled();
    });

    it('ignores a pull that starts with two fingers', () => {
      const fixture = render();
      const sheet = fixture.nativeElement as HTMLElement;

      touch('touchstart', sheet, 100, 100);
      touch('touchend', sheet, 300);

      expect(ref.close).not.toHaveBeenCalled();
    });
  });
});
