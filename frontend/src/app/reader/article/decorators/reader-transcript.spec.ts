import { ChapterPlayer } from '../../chapters/chapter-player';
import { TranscriptDto } from '../../models';
import { addTranscript } from './reader-transcript';

const TRANSCRIPT: TranscriptDto = {
  language: 'de',
  paragraphs: [
    { startSeconds: 0, speaker: 'Ann', text: 'Hallo.' },
    { startSeconds: 75, speaker: null, text: 'Weiter.' },
    { startSeconds: null, speaker: null, text: 'Ohne Zeit.' },
  ],
};

const playFromLabel = (time: string): string => `Play from ${time}`;

function bodyWith(html: string): HTMLElement {
  const host = document.createElement('div');
  host.innerHTML = html;
  return host;
}

function recordingPlayer(): ChapterPlayer & { playedFrom: number[] } {
  const playedFrom: number[] = [];
  return {
    playedFrom,
    playFrom: (seconds) => playedFrom.push(seconds),
    watch: () => undefined,
    release: () => undefined,
  };
}

describe('addTranscript', () => {
  it('appends a collapsed section after the body', () => {
    const host = bodyWith('<p>Notes</p>');

    const section = addTranscript(
      host,
      { transcript: TRANSCRIPT, heading: 'Transcript', playFromLabel, open: false },
      null,
    );

    expect(host.lastElementChild).toBe(section);
    expect(section?.querySelector('details')?.open).toBe(false);
  });

  it('opens when the transcript is the body', () => {
    const section = addTranscript(
      bodyWith(''),
      { transcript: TRANSCRIPT, heading: 'Transcript', playFromLabel, open: true },
      null,
    );

    expect(section?.querySelector('details')?.open).toBe(true);
  });

  it('seeks the player from a timestamp', () => {
    const player = recordingPlayer();
    const section = addTranscript(
      bodyWith(''),
      { transcript: TRANSCRIPT, heading: 'T', playFromLabel, open: true },
      player,
    );

    const stamps = section!.querySelectorAll<HTMLButtonElement>('.reader-transcript__time');
    stamps[1].click();

    expect(player.playedFrom).toEqual([75]);
    expect(stamps[0].getAttribute('aria-label')).toBe('Play from 0:00');
    expect(stamps[1].getAttribute('aria-label')).toBe('Play from 1:15');
    expect(stamps).toHaveLength(2);
  });

  it('shows timestamps as text with no player', () => {
    const section = addTranscript(
      bodyWith(''),
      { transcript: TRANSCRIPT, heading: 'T', playFromLabel, open: true },
      null,
    );

    expect(section!.querySelectorAll('button.reader-transcript__time')).toHaveLength(0);
    expect(section!.querySelectorAll('.reader-transcript__time')).toHaveLength(2);
  });

  it('names the speaker and keeps the text plain', () => {
    const section = addTranscript(
      bodyWith(''),
      {
        transcript: {
          language: null,
          paragraphs: [{ startSeconds: 0, speaker: 'Ann', text: '<b>x</b>' }],
        },
        heading: 'T',
        playFromLabel,
        open: true,
      },
      null,
    );

    expect(section!.querySelector('.reader-transcript__speaker')?.textContent).toBe('Ann');
    expect(section!.querySelector('.reader-transcript__text')?.textContent).toBe('<b>x</b>');
    expect(section!.querySelector('b')).toBeNull();
  });

  it('adds nothing for an empty transcript', () => {
    const view = {
      transcript: { language: null, paragraphs: [] },
      heading: 'T',
      playFromLabel,
      open: true,
    };

    expect(addTranscript(bodyWith(''), view, null)).toBeNull();
  });
});
