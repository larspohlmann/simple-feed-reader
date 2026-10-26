import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { CdkScrollable } from '@angular/cdk/scrolling';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { AudioPlayerBarComponent } from './audio-player-bar.component';
import { AudioPlayerService, AudioTrack, SKIP_SECONDS } from '../../audio-player.service';
import type { ChapterDto } from '../../models';
import { AudioSurface } from './audio-surface.service';
import { ScrubberComponent } from './scrubber/scrubber.component';

function stub() {
  return {
    current: signal<AudioTrack | null>(null),
    tracks: signal<AudioTrack[]>([]),
    index: signal(-1),
    hasNext: signal(false),
    playing: signal(false),
    position: signal(0),
    duration: signal(0),
    buffered: signal(0),
    chapters: signal<ChapterDto[]>([]),
    currentChapter: signal<ChapterDto | null>(null),
    hasPreviousChapter: signal(false),
    hasNextChapter: signal(false),
    toggle: jest.fn(),
    seek: jest.fn(),
    skip: jest.fn(),
    stop: jest.fn(),
    previous: jest.fn(),
    next: jest.fn(),
    previousChapter: jest.fn(),
    nextChapter: jest.fn(),
    playAt: jest.fn(),
    move: jest.fn(),
    dequeue: jest.fn(),
  };
}

let service: ReturnType<typeof stub>;

function render() {
  service = stub();
  TestBed.configureTestingModule({
    imports: [AudioPlayerBarComponent, provideTranslocoTesting()],
    providers: [{ provide: AudioPlayerService, useValue: service }],
  });
  const fixture = TestBed.createComponent(AudioPlayerBarComponent);
  fixture.detectChanges();
  return fixture;
}

function titleLine(fixture: ReturnType<typeof render>): string {
  const title: HTMLElement = fixture.debugElement.query(By.css('.title')).nativeElement;
  return title.textContent.replace(/\s+/g, ' ').trim();
}

function scrubber(fixture: ReturnType<typeof render>): HTMLInputElement {
  return fixture.debugElement
    .query(By.directive(ScrubberComponent))
    .query(By.css('input[type="range"]')).nativeElement;
}

const track: AudioTrack = {
  url: 'https://x.test/ep.mp3',
  title: 'Episode 1',
  faviconUrl: null,
  imageUrl: null,
  durationInSeconds: 120,
};

describe('AudioPlayerBarComponent', () => {
  it('renders nothing while no track is loaded', () => {
    const fixture = render();

    expect(fixture.nativeElement.textContent.trim()).toBe('');
  });

  it('shows the track title once a track is current', () => {
    const fixture = render();
    service.current.set(track);
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain('Episode 1');
  });

  it('toggles playback from the play/pause control', () => {
    const fixture = render();
    service.current.set(track);
    fixture.detectChanges();

    fixture.debugElement.query(By.css('.play')).nativeElement.click();

    expect(service.toggle).toHaveBeenCalled();
  });

  it('drives the scrubber from position and duration', () => {
    const fixture = render();
    service.current.set(track);
    service.duration.set(120);
    service.position.set(30);
    fixture.detectChanges();

    expect(scrubber(fixture).max).toBe('120');
    expect(scrubber(fixture).value).toBe('30');
  });

  it('draws how much of the stream is cached ahead of the playhead', () => {
    const fixture = render();
    service.current.set(track);
    service.duration.set(200);
    service.position.set(50);
    service.buffered.set(120);
    fixture.detectChanges();

    expect(scrubber(fixture).style.getPropertyValue('--played')).toBe('25%');
    expect(scrubber(fixture).style.getPropertyValue('--cached')).toBe('60%');
  });

  it('marks the chapters of the track on the scrubber', () => {
    const fixture = render();
    service.current.set(track);
    service.duration.set(120);
    service.chapters.set([
      { startSeconds: 0, title: 'Intro' },
      { startSeconds: 30, title: 'Main' },
    ]);
    fixture.detectChanges();

    const marks = fixture.debugElement.queryAll(By.css('.mark')).map((mark) => mark.nativeElement);
    expect(marks.map((mark: HTMLElement) => mark.style.left)).toEqual(['25%']);
  });

  it('seeks when the scrubber is moved', () => {
    const fixture = render();
    service.current.set(track);
    service.duration.set(120);
    fixture.detectChanges();

    scrubber(fixture).value = '75';
    scrubber(fixture).dispatchEvent(new Event('input'));

    expect(service.seek).toHaveBeenCalledWith(75);
  });

  it('skips backward and forward by the shared step', () => {
    const fixture = render();
    service.current.set(track);
    fixture.detectChanges();

    fixture.debugElement.query(By.css('.back')).nativeElement.click();
    fixture.debugElement.query(By.css('.forward')).nativeElement.click();

    expect(service.skip).toHaveBeenNthCalledWith(1, -SKIP_SECONDS);
    expect(service.skip).toHaveBeenNthCalledWith(2, SKIP_SECONDS);
  });

  it('closes the player', () => {
    const fixture = render();
    service.current.set(track);
    fixture.detectChanges();

    fixture.debugElement.query(By.css('.close')).nativeElement.click();

    expect(service.stop).toHaveBeenCalled();
  });

  it('opens the big player from the artwork', () => {
    const fixture = render();
    const openPlayer = jest.spyOn(TestBed.inject(AudioSurface), 'openPlayer').mockImplementation();
    service.current.set(track);
    fixture.detectChanges();

    const button: HTMLButtonElement = fixture.debugElement.query(
      By.css('.open-player'),
    ).nativeElement;
    button.click();

    expect(button.getAttribute('aria-label')).toBe('Open player');
    expect(openPlayer).toHaveBeenCalled();
  });

  describe('the chapters', () => {
    const intro: ChapterDto = { startSeconds: 0, title: 'Intro' };
    const main: ChapterDto = { startSeconds: 30, title: 'Main' };

    function chaptered(current: ChapterDto) {
      const fixture = render();
      service.current.set(track);
      service.chapters.set([intro, main]);
      service.currentChapter.set(current);
      fixture.detectChanges();
      return fixture;
    }

    function control(fixture: ReturnType<typeof render>, selector: string): HTMLButtonElement {
      return fixture.debugElement.query(By.css(selector)).nativeElement;
    }

    it('names the current chapter before the track title, muted', () => {
      const fixture = chaptered(main);

      expect(titleLine(fixture)).toBe('Main · Episode 1');
      expect(fixture.debugElement.query(By.css('.subtitle')).nativeElement.textContent.trim()).toBe(
        '· Episode 1',
      );
    });

    it('keeps the plain track title without a chapter', () => {
      const fixture = render();
      service.current.set(track);
      fixture.detectChanges();

      expect(titleLine(fixture)).toBe('Episode 1');
      expect(fixture.debugElement.query(By.css('.subtitle'))).toBeNull();
    });

    it('follows the chapter as playback moves on', () => {
      const fixture = chaptered(intro);
      expect(titleLine(fixture)).toBe('Intro · Episode 1');

      service.currentChapter.set(main);
      fixture.detectChanges();

      expect(titleLine(fixture)).toBe('Main · Episode 1');
    });

    it('offers the chapter steps only while the track has chapters', () => {
      const fixture = render();
      service.current.set(track);
      fixture.detectChanges();

      expect(fixture.debugElement.query(By.css('.previous-chapter'))).toBeNull();
      expect(fixture.debugElement.query(By.css('.next-chapter'))).toBeNull();

      service.chapters.set([intro, main]);
      fixture.detectChanges();

      expect(control(fixture, '.previous-chapter').getAttribute('aria-label')).toBe(
        'Previous chapter',
      );
      expect(control(fixture, '.next-chapter').getAttribute('aria-label')).toBe('Next chapter');
    });

    it('steps between chapters, leaving previous and next to the tracks', () => {
      const fixture = chaptered(main);
      service.hasPreviousChapter.set(true);
      service.hasNextChapter.set(true);
      fixture.detectChanges();

      control(fixture, '.previous-chapter').click();
      control(fixture, '.next-chapter').click();

      expect(service.previousChapter).toHaveBeenCalledTimes(1);
      expect(service.nextChapter).toHaveBeenCalledTimes(1);
      expect(service.previous).not.toHaveBeenCalled();
      expect(service.next).not.toHaveBeenCalled();
    });

    it('disables the chapter steps at the ends of the track', () => {
      const fixture = chaptered(intro);
      service.hasPreviousChapter.set(false);
      service.hasNextChapter.set(true);
      fixture.detectChanges();

      expect(control(fixture, '.previous-chapter').disabled).toBe(true);
      expect(control(fixture, '.next-chapter').disabled).toBe(false);

      service.hasPreviousChapter.set(true);
      service.hasNextChapter.set(false);
      fixture.detectChanges();

      expect(control(fixture, '.previous-chapter').disabled).toBe(false);
      expect(control(fixture, '.next-chapter').disabled).toBe(true);
    });

    it('groups the transport and the tail, so the phone can place them as rows', () => {
      const fixture = chaptered(main);

      const transport = fixture.debugElement
        .queryAll(By.css('.transport > .ctrl'))
        .map((button) => button.nativeElement.className);
      const tail = fixture.debugElement
        .queryAll(By.css('.tail > .ctrl'))
        .map((button) => button.nativeElement.className);

      expect(transport).toEqual([
        'ctrl previous-chapter',
        'ctrl previous',
        'ctrl back',
        'ctrl play',
        'ctrl forward',
        'ctrl next',
        'ctrl next-chapter',
      ]);
      expect(tail).toEqual(['ctrl toggle-playlist', 'ctrl close']);
    });
  });

  describe('the playlist', () => {
    const second: AudioTrack = { ...track, url: 'https://x.test/two.mp3', title: 'Episode 2' };
    const third: AudioTrack = {
      ...track,
      url: 'https://x.test/three.mp3',
      title: 'Episode 3',
      durationInSeconds: null,
    };

    function queued(index = 1) {
      const fixture = render();
      service.tracks.set([track, second, third]);
      service.index.set(index);
      service.current.set([track, second, third][index]);
      service.hasNext.set(index < 2);
      fixture.detectChanges();
      return fixture;
    }

    function open(fixture: ReturnType<typeof render>) {
      fixture.debugElement.query(By.css('.toggle-playlist')).nativeElement.click();
      fixture.detectChanges();
    }

    function rows(fixture: ReturnType<typeof render>): HTMLElement[] {
      return fixture.debugElement.queryAll(By.css('.row')).map((row) => row.nativeElement);
    }

    it('steps with previous and next', () => {
      const fixture = queued();

      fixture.debugElement.query(By.css('.previous')).nativeElement.click();
      fixture.debugElement.query(By.css('.next')).nativeElement.click();

      expect(service.previous).toHaveBeenCalled();
      expect(service.next).toHaveBeenCalled();
    });

    it('disables next on the last track', () => {
      const fixture = queued(2);

      expect(fixture.debugElement.query(By.css('.next')).nativeElement.disabled).toBe(true);
    });

    it('follows the shared surface state, so the big player can close it', () => {
      const fixture = queued();
      const surface = TestBed.inject(AudioSurface);

      surface.togglePlaylist();
      fixture.detectChanges();
      expect(rows(fixture)).toHaveLength(3);

      surface.togglePlaylist();
      fixture.detectChanges();
      expect(rows(fixture)).toHaveLength(0);
    });

    it('stays collapsed until the toggle opens it, and collapses again', () => {
      const fixture = queued();
      const toggle: HTMLButtonElement = fixture.debugElement.query(
        By.css('.toggle-playlist'),
      ).nativeElement;
      expect(rows(fixture)).toHaveLength(0);
      expect(toggle.getAttribute('aria-label')).toBe('Playlist (3)');

      open(fixture);
      expect(rows(fixture)).toHaveLength(3);
      expect(toggle.getAttribute('aria-expanded')).toBe('true');

      open(fixture);
      expect(rows(fixture)).toHaveLength(0);
    });

    it('scrolls the open panel itself, so a drag auto-scrolls it', () => {
      const fixture = queued();
      open(fixture);

      const panel = fixture.debugElement.query(By.directive(CdkScrollable));
      expect(panel.nativeElement.id).toBe('audio-playlist');
    });

    it('shows the artwork, and the favicon when a track has none', () => {
      const fixture = render();
      service.current.set({ ...track, imageUrl: 'https://x.test/art.jpg' });
      fixture.detectChanges();

      const artwork = fixture.debugElement.query(By.css('.controls app-audio-artwork'));
      expect(artwork.query(By.css('img')).nativeElement.getAttribute('src')).toBe(
        'https://x.test/art.jpg',
      );

      service.current.set(track);
      fixture.detectChanges();
      expect(artwork.query(By.css('img'))).toBeNull();
      expect(artwork.query(By.css('app-favicon'))).not.toBeNull();
    });
  });
});
