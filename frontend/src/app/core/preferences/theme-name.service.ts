import { effect, Injectable } from '@angular/core';
import { DEFAULT_THEME_NAME, THEMES } from '../../theme/themes/registry';
import { CachedAccountChoice } from './cached-account-choice';
import { THEME_NAME_KEY } from './theme-name';

@Injectable({ providedIn: 'root' })
export class ThemeNameService extends CachedAccountChoice<'theme'> {
  readonly name = this.value;

  constructor() {
    super({
      key: THEME_NAME_KEY,
      choices: THEMES,
      fallback: DEFAULT_THEME_NAME,
      field: 'theme',
    });
    effect(() => {
      const name = this.name();
      if (name === DEFAULT_THEME_NAME) document.documentElement.removeAttribute('data-theme-name');
      else document.documentElement.setAttribute('data-theme-name', name);
    });
  }
}
