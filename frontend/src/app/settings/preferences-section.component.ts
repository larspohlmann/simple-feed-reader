// src/app/settings/preferences-section.component.ts
import { ChangeDetectionStrategy, Component } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { LanguageSwitcherComponent } from '../shared/language-switcher/language-switcher.component';
import { SettingsCardComponent } from '../shared/settings-card/settings-card.component';

@Component({
  selector: 'app-preferences-section',
  imports: [LanguageSwitcherComponent, SettingsCardComponent, TranslocoPipe],
  templateUrl: './preferences-section.component.html',
  styleUrl: './preferences-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PreferencesSectionComponent {}
