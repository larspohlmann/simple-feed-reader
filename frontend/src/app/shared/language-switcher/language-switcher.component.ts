// src/app/shared/language-switcher/language-switcher.component.ts
import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { LanguageService } from '../../core/language.service';
import { LANGS } from '../../core/language';

/** A small segmented EN | DE control that switches the UI language at runtime. */
@Component({
  selector: 'app-language-switcher',
  imports: [TranslocoPipe],
  templateUrl: './language-switcher.component.html',
  styleUrl: './language-switcher.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class LanguageSwitcherComponent {
  protected readonly language = inject(LanguageService);
  protected readonly langs = LANGS;
}
