import { ChangeDetectionStrategy, Component } from '@angular/core';
import { BackupSectionComponent } from './backup-section.component';
import { OpmlSectionComponent } from './opml-section.component';
import { SettingsStackComponent } from '../shared/settings/stack/settings-stack.component';

/** The `/settings/import` page: OPML import/export and account backup, side
 *  by side in one stack.
 *
 *  Exists because `OpmlSectionComponent` used to render `<app-backup-section />`
 *  itself just to land both on one page (#454) -- crossing a component host
 *  boundary the global `app-settings-card + app-settings-card` gap couldn't
 *  reach, forcing a compensating margin on the backup card. A page whose only
 *  job is composing the two removes that. */
@Component({
  selector: 'app-import-section',
  imports: [BackupSectionComponent, OpmlSectionComponent, SettingsStackComponent],
  templateUrl: './import-section.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ImportSectionComponent {}
