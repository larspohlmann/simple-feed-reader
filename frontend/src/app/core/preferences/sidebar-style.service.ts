import { Injectable, computed } from '@angular/core';
import { CachedAccountChoice } from './cached-account-choice';
import { SIDEBAR_STYLES, SIDEBAR_STYLE_KEY } from './sidebar-style';

@Injectable({ providedIn: 'root' })
export class SidebarStyleService extends CachedAccountChoice<'sidebarStyle'> {
  readonly style = this.value;

  readonly airy = computed(() => this.style() === 'airy');

  constructor() {
    super({
      key: SIDEBAR_STYLE_KEY,
      choices: SIDEBAR_STYLES,
      fallback: 'boxed',
      field: 'sidebarStyle',
    });
  }
}
