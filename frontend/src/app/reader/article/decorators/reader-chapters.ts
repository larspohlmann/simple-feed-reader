import { ChapterDto } from '../../models';
import { ChapterPlayer, PlaybackPosition } from '../../chapters/chapter-player';
import {
  ChapterProgress,
  chapterProgress,
  currentChapterIndex,
} from '../../chapters/current-chapter';
import { formatDuration } from '../../format';
import { materialIcon } from './material-icon';

const LIST = 'reader-chapters';
const PROGRESS = '--chapter-progress';
const TIME_LEFT = `${LIST}__left`;

export interface ChapterList {
  readonly chapters: ChapterDto[];
  readonly heading: string;
}

/** A collapsed chapter list whose rows start `player` at their chapter and follow its position (#1808). */
export function addChapterList(
  after: Element,
  { chapters, heading }: ChapterList,
  player: ChapterPlayer,
): HTMLElement | null {
  if (chapters.length === 0) return null;
  const rows = chapters.map((chapter) => chapterRow(chapter));
  let marked = -1;
  const mark = (index: number): void => {
    if (index === marked) return;
    const previous = rows[marked];
    if (previous) {
      previous.removeAttribute('aria-current');
      clearProgress(previous);
    }
    rows[index]?.setAttribute('aria-current', 'true');
    marked = index;
  };
  rows.forEach((row, index) =>
    row.addEventListener('click', () => {
      player.playFrom(chapters[index].startSeconds);
      mark(index);
    }),
  );
  const starts = chapters.map((chapter) => chapter.startSeconds);
  const follow = (position: PlaybackPosition | null): void => {
    const index = currentChapterIndex(starts, position?.seconds ?? null);
    mark(index);
    if (position && index >= 0) showProgress(rows[index], chapterProgress(starts, index, position));
  };
  player.watch(follow);

  const list = document.createElement('nav');
  list.className = LIST;
  list.setAttribute('aria-label', heading);
  const items = document.createElement('ol');
  items.className = `${LIST}__list`;
  for (const row of rows) {
    const item = document.createElement('li');
    item.append(row);
    items.append(item);
  }
  list.append(toggle(heading, items), items);
  after.after(list);
  return list;
}

function toggle(heading: string, items: HTMLElement): HTMLButtonElement {
  const button = document.createElement('button');
  button.type = 'button';
  button.className = `${LIST}__toggle`;
  const icon = materialIcon('chevron_right');
  button.append(icon, heading);
  const show = (open: boolean): void => {
    button.setAttribute('aria-expanded', String(open));
    icon.textContent = open ? 'expand_more' : 'chevron_right';
    items.hidden = !open;
  };
  show(false);
  button.addEventListener('click', () => show(items.hidden));
  return button;
}

function showProgress(row: HTMLElement, progress: ChapterProgress | null): void {
  if (progress === null) {
    clearProgress(row);
    return;
  }
  row.style.setProperty(PROGRESS, progress.fraction.toFixed(4));
  row
    .querySelector(`.${TIME_LEFT}`)
    ?.replaceChildren(materialIcon('schedule'), remaining(progress.secondsLeft));
}

function remaining(seconds: number): HTMLSpanElement {
  const time = document.createElement('span');
  time.className = `${LIST}__remaining`;
  time.textContent = formatDuration(seconds);
  return time;
}

function clearProgress(row: HTMLElement): void {
  row.style.removeProperty(PROGRESS);
  row.querySelector(`.${TIME_LEFT}`)?.replaceChildren();
}

function chapterRow(chapter: ChapterDto): HTMLButtonElement {
  const time = document.createElement('span');
  time.className = `${LIST}__time`;
  time.textContent = formatDuration(chapter.startSeconds);
  const title = document.createElement('span');
  title.className = `${LIST}__title`;
  title.textContent = chapter.title;
  const timeLeft = document.createElement('span');
  timeLeft.className = TIME_LEFT;
  timeLeft.setAttribute('aria-hidden', 'true');
  const row = document.createElement('button');
  row.type = 'button';
  row.className = `${LIST}__row`;
  row.append(time, title, timeLeft);
  return row;
}
