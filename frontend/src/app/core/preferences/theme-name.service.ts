import { Injectable } from '@angular/core';
import { DEFAULT_THEME_NAME, ThemeName } from '../../theme/themes/registry';
import { CurrentUser } from '../auth/auth.service';
import { CachedAccountChoice } from './cached-account-choice';
import { THEME_NAMES, THEME_NAME_KEY } from './theme-name';

@Injectable({ providedIn: 'root' })
export class ThemeNameService extends CachedAccountChoice<'theme'> {
  readonly name = this.value;

  constructor() {
    super({
      key: THEME_NAME_KEY,
      choices: THEME_NAMES,
      fallback: DEFAULT_THEME_NAME,
      field: 'theme',
    });
    this.applyAttribute();
  }

  override set(choice: ThemeName): void {
    super.set(choice);
    this.applyAttribute();
  }

  override adopt(user: CurrentUser): void {
    super.adopt(user);
    this.applyAttribute();
  }

  override reset(): void {
    super.reset();
    this.applyAttribute();
  }

  private applyAttribute(): void {
    const root = document.documentElement;
    const name = this.name();
    if (name === DEFAULT_THEME_NAME) root.removeAttribute('data-theme-name');
    else root.setAttribute('data-theme-name', name);
  }
}
