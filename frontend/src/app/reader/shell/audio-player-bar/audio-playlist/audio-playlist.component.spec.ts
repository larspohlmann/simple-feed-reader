import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { CdkDragDrop, CdkDropList } from '@angular/cdk/drag-drop';
import { provideTranslocoTesting } from '../../../../../testing/transloco-testing';
import { AudioPlayerService, AudioTrack } from '../../../audio-player.service';
import { AudioPlaylistComponent } from './audio-playlist.component';

const first: AudioTrack = {
  url: 'https://x.test/ep.mp3',
  title: 'Episode 1',
  faviconUrl: null,
  imageUrl: null,
  durationInSeconds: 120,
};
const second: AudioTrack = { ...first, url: 'https://x.test/two.mp3', title: 'Episode 2' };
const third: AudioTrack = {
  ...first,
  url: 'https://x.test/three.mp3',
  title: 'Episode 3',
  durationInSeconds: null,
};

function stub() {
  return {
    tracks: signal<AudioTrack[]>([first, second, third]),
    index: signal(1),
    duration: signal(0),
    playAt: jest.fn(),
    move: jest.fn(),
    dequeue: jest.fn(),
  };
}

let player: ReturnType<typeof stub>;

function render() {
  player = stub();
  TestBed.configureTestingModule({
    imports: [AudioPlaylistComponent, provideTranslocoTesting()],
    providers: [{ provide: AudioPlayerService, useValue: player }],
  });
  const fixture = TestBed.createComponent(AudioPlaylistComponent);
  fixture.detectChanges();
  return fixture;
}

function rows(fixture: ReturnType<typeof render>): HTMLElement[] {
  return fixture.debugElement.queryAll(By.css('.row')).map((row) => row.nativeElement);
}

function click(row: HTMLElement, selector: string): void {
  (row.querySelector(selector) as HTMLButtonElement).click();
}

describe('AudioPlaylistComponent', () => {
  it('lists every track with its length, marking the current and the played ones', () => {
    const [earlier, current, later] = rows(render());

    expect(current.getAttribute('aria-current')).toBe('true');
    expect(earlier.classList).toContain('played');
    expect(later.classList).not.toContain('played');
    expect(earlier.textContent).toContain('2:00');
    expect(later.querySelector('.row-time')).toBeNull();
  });

  it('shows the current row with the duration the element reports', () => {
    const fixture = render();
    player.duration.set(150);
    fixture.detectChanges();

    expect(rows(fixture)[1].querySelector('.row-time')?.textContent).toContain('2:30');
  });

  it('plays, moves and removes from the row controls', () => {
    const [earlier, current, later] = rows(render());

    click(later, '.pick');
    click(current, '.up');
    click(current, '.down');
    click(earlier, '.remove');

    expect(player.playAt).toHaveBeenCalledWith(2);
    expect(player.move).toHaveBeenNthCalledWith(1, 1, 0);
    expect(player.move).toHaveBeenNthCalledWith(2, 1, 2);
    expect(player.dequeue).toHaveBeenCalledWith(first.url);
  });

  it('disables moving past either end', () => {
    const [earlier, , later] = rows(render());

    expect((earlier.querySelector('.up') as HTMLButtonElement).disabled).toBe(true);
    expect((later.querySelector('.down') as HTMLButtonElement).disabled).toBe(true);
  });

  it('moves a dragged row to where it was dropped', () => {
    const fixture = render();

    const list = fixture.debugElement.query(By.directive(CdkDropList)).injector.get(CdkDropList);
    list.dropped.emit({ previousIndex: 2, currentIndex: 0 } as CdkDragDrop<AudioTrack[]>);

    expect(player.move).toHaveBeenCalledWith(2, 0);
  });
});
