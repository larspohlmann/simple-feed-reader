import { TestBed } from '@angular/core/testing';
import { StubAudioPlayer } from '../../../../testing/stub-audio-player';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { AudioPlayerService, AudioTrack } from '../../audio-player.service';
import { ChapterDto } from '../../models';
import { EpisodeChaptersComponent } from './episode-chapters.component';

const CHAPTERS: ChapterDto[] = [
  { startSeconds: 0, title: 'Intro' },
  { startSeconds: 256, title: 'How it works' },
];
const EPISODE: AudioTrack = {
  url: 'https://x.test/ep.mp3',
  title: 'Ep',
  faviconUrl: null,
  imageUrl: null,
  durationInSeconds: 600,
};

function render() {
  const player = new StubAudioPlayer();
  TestBed.configureTestingModule({
    imports: [EpisodeChaptersComponent, provideTranslocoTesting()],
    providers: [{ provide: AudioPlayerService, useValue: player }],
  });
  const fixture = TestBed.createComponent(EpisodeChaptersComponent);
  fixture.componentRef.setInput('chapters', CHAPTERS);
  fixture.componentRef.setInput('track', EPISODE);
  fixture.detectChanges();
  const element = fixture.nativeElement as HTMLElement;
  element.querySelector<HTMLButtonElement>('.reader-panel__toggle')!.click();
  fixture.detectChanges();
  return { fixture, element, player };
}

const rows = (element: HTMLElement) =>
  Array.from(element.querySelectorAll<HTMLButtonElement>('.reader-chapters__row'));

describe('EpisodeChaptersComponent', () => {
  it('lists the episode chapters in a labelled panel', () => {
    const { element } = render();

    expect(element.querySelector('nav')!.getAttribute('aria-label')).toBe('Chapters');
    expect(rows(element).map((row) => row.textContent!.replace(/\s+/g, ''))).toEqual([
      '0:00Intro',
      '4:16Howitworks',
    ]);
  });

  it('comes back collapsed for another entry', () => {
    const { fixture, element } = render();

    fixture.componentRef.setInput('chapters', [{ startSeconds: 0, title: 'Other' }]);
    fixture.detectChanges();

    expect(element.querySelector('.reader-panel__toggle')!.getAttribute('aria-expanded')).toBe(
      'false',
    );
    expect(rows(element)).toHaveLength(0);
  });

  it('starts the episode at a picked chapter', () => {
    const { element, player } = render();

    rows(element)[1].click();

    expect(player.playedFrom).toEqual([{ track: EPISODE, seconds: 256 }]);
  });

  it('marks the playing chapter while its own track plays', () => {
    const { fixture, element, player } = render();

    player.current.set(EPISODE);
    player.playbackPosition.set({ seconds: 300, durationSeconds: 600 });
    fixture.detectChanges();

    expect(rows(element)[1].getAttribute('aria-current')).toBe('true');
    expect(rows(element)[0].hasAttribute('aria-current')).toBe(false);
  });

  it('marks no chapter while another track plays', () => {
    const { fixture, element, player } = render();

    player.current.set({ ...EPISODE, url: 'https://x.test/other.mp3' });
    player.playbackPosition.set({ seconds: 300, durationSeconds: 600 });
    fixture.detectChanges();

    expect(rows(element).some((row) => row.hasAttribute('aria-current'))).toBe(false);
  });

  it('marks no chapter when nothing plays', () => {
    const { element } = render();

    expect(rows(element).some((row) => row.hasAttribute('aria-current'))).toBe(false);
  });
});
