import { ChangeDetectionStrategy, Component } from '@angular/core';
import { BackupSectionComponent } from '../backup/backup-section.component';
import { OpmlSectionComponent } from './opml-section.component';
import { SettingsStackComponent } from '../../shared/settings/stack/settings-stack.component';

/** The `/settings/import` page: OPML import/export and account backup, side
 *  by side in one stack.
 *
 *  A page of its own so the global `app-settings-card + app-settings-card` gap
 *  reaches both cards; no host boundary sits between them (#454). */
@Component({
  selector: 'app-import-section',
  imports: [BackupSectionComponent, OpmlSectionComponent, SettingsStackComponent],
  templateUrl: './import-section.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ImportSectionComponent {}
