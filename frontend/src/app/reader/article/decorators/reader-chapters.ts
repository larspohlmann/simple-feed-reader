import { ChapterDto } from '../../models';
import { ChapterPlayer } from '../../chapters/chapter-player';
import { currentChapterIndex } from '../../chapters/current-chapter';
import { formatDuration } from '../../format';

const LIST = 'reader-chapters';

export interface ChapterList {
  readonly chapters: ChapterDto[];
  readonly heading: string;
}

/** Where a chapter list goes: after `after`, or first in `host`. */
export interface ChapterListPlace {
  readonly host: HTMLElement;
  readonly after: Element | null;
}

/**
 * A chapter list whose rows start `player` at their chapter and follow its position; it replaces
 * any earlier list in `host` (#1808).
 */
export function addChapterList(
  { host, after }: ChapterListPlace,
  { chapters, heading }: ChapterList,
  player: ChapterPlayer,
): HTMLElement | null {
  host.querySelector(`.${LIST}`)?.remove();
  if (chapters.length === 0) return null;
  const rows = chapters.map((chapter) => chapterRow(chapter));
  const mark = (index: number): void => {
    for (const [at, row] of rows.entries()) {
      if (at === index) row.setAttribute('aria-current', 'true');
      else row.removeAttribute('aria-current');
    }
  };
  rows.forEach((row, index) =>
    row.addEventListener('click', () => {
      player.playFrom(chapters[index].startSeconds);
      mark(index);
    }),
  );
  const starts = chapters.map((chapter) => chapter.startSeconds);
  player.watch((seconds) => mark(currentChapterIndex(starts, seconds)));

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
  list.append(items);
  if (after) after.after(list);
  else host.prepend(list);
  return list;
}

function chapterRow(chapter: ChapterDto): HTMLButtonElement {
  const time = document.createElement('span');
  time.className = `${LIST}__time`;
  time.textContent = formatDuration(chapter.startSeconds);
  const title = document.createElement('span');
  title.className = `${LIST}__title`;
  title.textContent = chapter.title;
  const row = document.createElement('button');
  row.type = 'button';
  row.className = `${LIST}__row`;
  row.append(time, title);
  return row;
}
