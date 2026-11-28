import { Injectable } from '@angular/core';
import { CachedAccountChoice } from './cached-account-choice';
import { DEFAULT_READING_WIDTH, READING_WIDTHS, READING_WIDTH_KEY } from './reading-width';

@Injectable({ providedIn: 'root' })
export class ReadingWidthService extends CachedAccountChoice<'readingWidth'> {
  readonly width = this.value;

  constructor() {
    super({
      key: READING_WIDTH_KEY,
      choices: READING_WIDTHS,
      fallback: DEFAULT_READING_WIDTH,
      field: 'readingWidth',
    });
  }
}
