import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { AudioPlayerService } from '../../audio-player.service';
import { ChapterDto } from '../../models';
import { EpisodeChaptersComponent } from './episode-chapters.component';

const CHAPTERS: ChapterDto[] = [
  { startSeconds: 0, title: 'Intro' },
  { startSeconds: 256, title: 'How it works' },
];
const EPISODE = {
  url: 'https://x.test/ep.mp3',
  title: 'Ep',
  faviconUrl: null,
  imageUrl: null,
  durationInSeconds: 600,
};

function render() {
  TestBed.configureTestingModule({
    imports: [EpisodeChaptersComponent, provideTranslocoTesting()],
    providers: [
      {
        provide: AudioPlayerService,
        useValue: {
          current: signal(null),
          playbackPosition: signal({ seconds: 0, durationSeconds: null }),
          playFrom: () => undefined,
        },
      },
    ],
  });
  const fixture = TestBed.createComponent(EpisodeChaptersComponent);
  fixture.componentRef.setInput('chapters', CHAPTERS);
  fixture.componentRef.setInput('track', EPISODE);
  fixture.detectChanges();
  const element = fixture.nativeElement as HTMLElement;
  element.querySelector<HTMLButtonElement>('.reader-panel__toggle')!.click();
  fixture.detectChanges();
  return { fixture, element };
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

  it('starts the episode at a picked chapter', () => {
    const { element } = render();
    const playFrom = jest
      .spyOn(TestBed.inject(AudioPlayerService), 'playFrom')
      .mockImplementation(() => undefined);

    rows(element)[1].click();

    expect(playFrom).toHaveBeenCalledWith(EPISODE, 256);
  });

  it('marks no chapter while another track plays', () => {
    const { element } = render();

    expect(rows(element).some((row) => row.hasAttribute('aria-current'))).toBe(false);
  });
});
