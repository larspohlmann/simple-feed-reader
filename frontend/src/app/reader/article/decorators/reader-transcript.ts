import { TranscriptDto, TranscriptParagraphDto } from '../../models';
import { ChapterPlayer } from '../../chapters/chapter-player';
import { formatDuration } from '../../format';
import { materialIcon } from './material-icon';

const SECTION = 'reader-transcript';

export interface TranscriptView {
  readonly transcript: TranscriptDto;
  readonly heading: string;
  readonly playFromLabel: (time: string) => string;
  readonly open: boolean;
}

/** An episode's transcript under its body; with a player, each timestamp starts it there (#1809). */
export function addTranscript(
  host: HTMLElement,
  { transcript, heading, playFromLabel, open }: TranscriptView,
  player: ChapterPlayer | null,
): HTMLElement | null {
  if (transcript.paragraphs.length === 0) return null;
  const summary = document.createElement('summary');
  summary.className = `${SECTION}__toggle`;
  summary.append(materialIcon('chevron_right'), heading);
  const details = document.createElement('details');
  details.open = open;
  details.append(
    summary,
    ...transcript.paragraphs.map((paragraph) => paragraphOf(paragraph, player, playFromLabel)),
  );
  const section = document.createElement('section');
  section.className = SECTION;
  section.setAttribute('aria-label', heading);
  if (transcript.language) section.lang = transcript.language;
  section.append(details);
  host.append(section);
  return section;
}

function paragraphOf(
  paragraph: TranscriptParagraphDto,
  player: ChapterPlayer | null,
  playFromLabel: (time: string) => string,
): HTMLParagraphElement {
  const element = document.createElement('p');
  element.className = `${SECTION}__paragraph`;
  if (paragraph.startSeconds !== null)
    element.append(timeOf(paragraph.startSeconds, player, playFromLabel));
  if (paragraph.speaker) {
    const speaker = document.createElement('strong');
    speaker.className = `${SECTION}__speaker`;
    speaker.textContent = paragraph.speaker;
    element.append(speaker);
  }
  const text = document.createElement('span');
  text.className = `${SECTION}__text`;
  text.textContent = paragraph.text;
  element.append(text);
  return element;
}

function timeOf(
  seconds: number,
  player: ChapterPlayer | null,
  playFromLabel: (time: string) => string,
): HTMLElement {
  const time = document.createElement(player ? 'button' : 'span');
  time.className = `${SECTION}__time`;
  const formatted = formatDuration(seconds);
  time.textContent = formatted;
  if (player && time instanceof HTMLButtonElement) {
    time.type = 'button';
    time.setAttribute('aria-label', playFromLabel(formatted));
    time.addEventListener('click', () => player.playFrom(seconds));
  }
  return time;
}
