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

/** An episode's transcript atop its body; with a player, each timestamp starts it there (#1809). */
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
  const timeAt = player ? seekButton(player, playFromLabel) : plainTime;
  details.append(
    summary,
    ...transcript.paragraphs.map((paragraph) => paragraphOf(paragraph, timeAt)),
  );
  const section = document.createElement('section');
  section.className = SECTION;
  section.setAttribute('aria-label', heading);
  if (transcript.language) section.lang = transcript.language;
  section.append(details);
  host.prepend(section);
  return section;
}

function paragraphOf(
  paragraph: TranscriptParagraphDto,
  timeAt: (seconds: number) => HTMLElement,
): HTMLParagraphElement {
  const element = document.createElement('p');
  element.className = `${SECTION}__paragraph`;
  if (paragraph.startSeconds !== null) element.append(timeAt(paragraph.startSeconds));
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

function seekButton(
  player: ChapterPlayer,
  playFromLabel: (time: string) => string,
): (seconds: number) => HTMLElement {
  return (seconds) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `${SECTION}__time`;
    const time = formatDuration(seconds);
    button.textContent = time;
    button.setAttribute('aria-label', playFromLabel(time));
    button.addEventListener('click', () => player.playFrom(seconds));
    return button;
  };
}

function plainTime(seconds: number): HTMLElement {
  const time = document.createElement('span');
  time.className = `${SECTION}__time`;
  time.textContent = formatDuration(seconds);
  return time;
}
