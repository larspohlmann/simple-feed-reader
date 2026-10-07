import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { CdkDragDrop, CdkDropList } from '@angular/cdk/drag-drop';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { AudioPlayerBarComponent } from './audio-player-bar.component';
import { AudioPlayerService, AudioTrack, SKIP_SECONDS } from '../../audio-player.service';

function stub() {
  return {
    current: signal<AudioTrack | null>(null),
    tracks: signal<AudioTrack[]>([]),
    index: signal(-1),
    hasNext: signal(false),
    playing: signal(false),
    position: signal(0),
    duration: signal(0),
    toggle: jest.fn(),
    seek: jest.fn(),
    skip: jest.fn(),
    stop: jest.fn(),
    previous: jest.fn(),
    next: jest.fn(),
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

    const scrubber: HTMLInputElement = fixture.debugElement.query(
      By.css('.scrubber'),
    ).nativeElement;

    expect(scrubber.max).toBe('120');
    expect(scrubber.value).toBe('30');
  });

  it('seeks when the scrubber is moved', () => {
    const fixture = render();
    service.current.set(track);
    service.duration.set(120);
    fixture.detectChanges();

    const scrubber: HTMLInputElement = fixture.debugElement.query(
      By.css('.scrubber'),
    ).nativeElement;
    scrubber.value = '75';
    scrubber.dispatchEvent(new Event('input'));

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

    function click(row: HTMLElement, selector: string): void {
      (row.querySelector(selector) as HTMLButtonElement).click();
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

    it('lists every track with its length, marking the current and the played ones', () => {
      const fixture = queued();
      open(fixture);

      const [first, current, last] = rows(fixture);
      expect(current.getAttribute('aria-current')).toBe('true');
      expect(first.classList).toContain('played');
      expect(last.classList).not.toContain('played');
      expect(first.textContent).toContain('2:00');
      expect(last.querySelector('.row-time')).toBeNull();
    });

    it('shows the current row with the duration the element reports', () => {
      const fixture = queued();
      service.duration.set(150);
      open(fixture);

      expect(rows(fixture)[1].querySelector('.row-time')?.textContent).toContain('2:30');
    });

    it('plays, moves and removes from the row controls', () => {
      const fixture = queued();
      open(fixture);
      const [first, current, last] = rows(fixture);

      click(last, '.pick');
      click(current, '.up');
      click(current, '.down');
      click(first, '.remove');

      expect(service.playAt).toHaveBeenCalledWith(2);
      expect(service.move).toHaveBeenNthCalledWith(1, 1, 0);
      expect(service.move).toHaveBeenNthCalledWith(2, 1, 2);
      expect(service.dequeue).toHaveBeenCalledWith(track.url);
    });

    it('disables moving past either end', () => {
      const fixture = queued();
      open(fixture);
      const [first, , last] = rows(fixture);

      expect((first.querySelector('.up') as HTMLButtonElement).disabled).toBe(true);
      expect((last.querySelector('.down') as HTMLButtonElement).disabled).toBe(true);
    });

    it('moves a dragged row to where it was dropped', () => {
      const fixture = queued();
      open(fixture);

      const list = fixture.debugElement.query(By.directive(CdkDropList)).injector.get(CdkDropList);
      list.dropped.emit({ previousIndex: 2, currentIndex: 0 } as CdkDragDrop<AudioTrack[]>);

      expect(service.move).toHaveBeenCalledWith(2, 0);
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
