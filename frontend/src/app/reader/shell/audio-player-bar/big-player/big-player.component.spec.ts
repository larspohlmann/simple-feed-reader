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
    const scrubber = query<HTMLInputElement>(fixture, '.scrubber')!;

    expect(scrubber.max).toBe('600');
    expect(scrubber.value).toBe('90');
    expect(scrubber.style.getPropertyValue('--played')).toBe('15%');
    const times = fixture.debugElement.queryAll(By.css('.time')).map((time) => time.nativeElement);
    expect(times.map((time) => time.textContent.trim())).toEqual(['1:30', '−8:30']);

    scrubber.value = '75';
    scrubber.dispatchEvent(new Event('input'));
    expect(player.seek).toHaveBeenCalledWith(75);
  });

  it('thickens the scrubber while it is held', () => {
    const fixture = render();
    const scrubber = query(fixture, '.scrubber')!;

    scrubber.dispatchEvent(new Event('pointerdown'));
    fixture.detectChanges();
    expect(query(fixture, '.scrub')!.classList).toContain('dragging');

    scrubber.dispatchEvent(new Event('pointerup'));
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

      pull(query(fixture, '.scrubber')!, [100, 300]);

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
