// src/app/settings/preferences-section.component.ts
import { ChangeDetectionStrategy, Component } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { LanguageSwitcherComponent } from '../shared/language-switcher/language-switcher.component';

@Component({
  selector: 'app-preferences-section',
  imports: [TranslocoPipe, LanguageSwitcherComponent],
  templateUrl: './preferences-section.component.html',
  styleUrl: './preferences-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PreferencesSectionComponent {}
