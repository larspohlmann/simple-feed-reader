import { Injectable } from '@angular/core';
import { MAGAZINE_STYLES, MAGAZINE_STYLE_KEY } from './magazine-style';
import { CachedAccountChoice } from './cached-account-choice';

@Injectable({ providedIn: 'root' })
export class MagazineStyleService extends CachedAccountChoice<'magazineStyle'> {
  readonly style = this.value;

  constructor() {
    super({
      key: MAGAZINE_STYLE_KEY,
      choices: MAGAZINE_STYLES,
      fallback: 'boxed',
      field: 'magazineStyle',
    });
  }
}
