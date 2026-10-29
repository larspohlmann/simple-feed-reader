import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { AudioPlayerService, AudioTrack } from '../../audio-player.service';
import { TranscriptDto } from '../../models';
import { EpisodeTranscriptComponent } from './episode-transcript.component';

const TRANSCRIPT: TranscriptDto = {
  language: 'de',
  paragraphs: [
    { startSeconds: 0, speaker: 'Ann', text: 'Hallo.' },
    { startSeconds: 75, speaker: null, text: 'Weiter.' },
    { startSeconds: null, speaker: null, text: 'Ohne Zeit.' },
  ],
};
const EPISODE: AudioTrack = {
  url: 'https://x.test/ep.mp3',
  title: 'Ep',
  faviconUrl: null,
  imageUrl: null,
  durationInSeconds: 600,
};

interface Options {
  readonly transcript?: TranscriptDto;
  readonly track?: AudioTrack | null;
  readonly startOpen?: boolean;
  readonly open?: boolean;
}

function render({
  transcript = TRANSCRIPT,
  track = EPISODE,
  startOpen = false,
  open = true,
}: Options = {}) {
  const player = { playFrom: jest.fn() };
  TestBed.configureTestingModule({
    imports: [EpisodeTranscriptComponent, provideTranslocoTesting()],
    providers: [{ provide: AudioPlayerService, useValue: player }],
  });
  const fixture = TestBed.createComponent(EpisodeTranscriptComponent);
  fixture.componentRef.setInput('transcript', transcript);
  fixture.componentRef.setInput('track', track);
  fixture.componentRef.setInput('startOpen', startOpen);
  fixture.detectChanges();
  const element = fixture.nativeElement as HTMLElement;
  if (open) {
    element.querySelector<HTMLButtonElement>('.reader-panel__toggle')!.click();
    fixture.detectChanges();
  }
  return { fixture, element, player };
}

const toggle = (element: HTMLElement) =>
  element.querySelector<HTMLButtonElement>('.reader-panel__toggle')!;
const stamps = (element: HTMLElement) =>
  Array.from(element.querySelectorAll<HTMLElement>('.reader-transcript__time'));

describe('EpisodeTranscriptComponent', () => {
  it('renders nothing without paragraphs', () => {
    const { element } = render({
      transcript: { language: null, paragraphs: [] },
      open: false,
    });

    expect(element.querySelector('section')).toBeNull();
    expect(element.querySelector('.reader-panel__toggle')).toBeNull();
  });

  it('is a collapsed panel labelled Transcript in the transcript language', () => {
    const { element } = render({ open: false });

    expect(toggle(element).textContent).toContain('Transcript');
    expect(toggle(element).getAttribute('aria-expanded')).toBe('false');
    expect(element.querySelector('.reader-transcript__paragraph')).toBeNull();
    const section = element.querySelector('section')!;
    expect(section.getAttribute('aria-label')).toBe('Transcript');
    expect(section.getAttribute('lang')).toBe('de');
  });

  it('starts open when asked to', () => {
    const { element } = render({ startOpen: true, open: false });

    expect(toggle(element).getAttribute('aria-expanded')).toBe('true');
    expect(element.querySelectorAll('.reader-transcript__paragraph')).toHaveLength(3);
  });

  it('comes back collapsed for another entry', () => {
    const { fixture, element } = render();

    fixture.componentRef.setInput('transcript', { ...TRANSCRIPT });
    fixture.detectChanges();

    expect(toggle(element).getAttribute('aria-expanded')).toBe('false');
    expect(element.querySelector('.reader-transcript__paragraph')).toBeNull();
  });

  it('comes back open for another entry when asked to start open', () => {
    const { fixture, element } = render({ startOpen: true, open: false });
    toggle(element).click();
    fixture.detectChanges();

    fixture.componentRef.setInput('transcript', { ...TRANSCRIPT });
    fixture.detectChanges();

    expect(toggle(element).getAttribute('aria-expanded')).toBe('true');
  });

  it('opens on a click of the toggle', () => {
    const { element } = render();

    expect(toggle(element).getAttribute('aria-expanded')).toBe('true');
    expect(element.querySelectorAll('.reader-transcript__paragraph')).toHaveLength(3);
  });

  it('starts the episode at a timestamp', () => {
    const { element, player } = render();
    const buttons = stamps(element);

    buttons[1].click();

    expect(player.playFrom).toHaveBeenCalledTimes(1);
    expect(player.playFrom).toHaveBeenCalledWith(EPISODE, 75);
    expect(buttons).toHaveLength(2);
    expect(buttons[0].tagName).toBe('BUTTON');
    expect(buttons[0].getAttribute('aria-label')).toBe('Play from 0:00');
    expect(buttons[1].getAttribute('aria-label')).toBe('Play from 1:15');
  });

  it('shows timestamps as text without a track', () => {
    const { element } = render({ track: null });

    expect(element.querySelectorAll('button.reader-transcript__time')).toHaveLength(0);
    expect(stamps(element).map((stamp) => stamp.tagName)).toEqual(['SPAN', 'SPAN']);
    expect(stamps(element).map((stamp) => stamp.textContent!.trim())).toEqual(['0:00', '1:15']);
  });

  it('names the speaker and keeps the text plain', () => {
    const { element } = render({
      transcript: {
        language: null,
        paragraphs: [{ startSeconds: 0, speaker: 'Ann', text: '<b>x</b>' }],
      },
    });

    expect(element.querySelector('.reader-transcript__speaker')!.textContent).toBe('Ann');
    expect(element.querySelector('.reader-transcript__text')!.textContent).toBe('<b>x</b>');
    expect(element.querySelector('b')).toBeNull();
  });

  it('gives a paragraph without a start no time', () => {
    const { element } = render();
    const paragraphs = element.querySelectorAll('.reader-transcript__paragraph');

    expect(paragraphs[2].querySelector('.reader-transcript__time')).toBeNull();
    expect(paragraphs[2].textContent).toContain('Ohne Zeit.');
  });
});
