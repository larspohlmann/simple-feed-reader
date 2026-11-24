import { Component, inject, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { Problem } from '../../core/problem';
import { downloadOpmlExport } from './opml-export';
import { SettingsApi } from '../settings-api';
import { OpmlImportResult } from '../settings.models';
import { ButtonComponent } from '../../shared/button/button.component';
import { ErrorBannerComponent } from '../../shared/error-banner/error-banner.component';
import { SettingsGroupComponent } from '../../shared/settings/settings-group/settings-group.component';

@Component({
  selector: 'app-opml-section',
  imports: [ButtonComponent, ErrorBannerComponent, SettingsGroupComponent, TranslocoPipe],
  templateUrl: './opml-section.component.html',
  styleUrl: './opml-section.component.scss',
})
export class OpmlSectionComponent {
  private readonly api = inject(SettingsApi);

  readonly exporting = signal(false);
  readonly result = signal<OpmlImportResult | null>(null);
  readonly exportError = signal<Problem | null>(null);
  readonly importError = signal<Problem | null>(null);

  exportOpml(): void {
    downloadOpmlExport(this.api, this.exporting, this.exportError);
  }
}
