// src/app/settings/import-section.component.ts
import { ChangeDetectionStrategy, Component } from '@angular/core';
import { BackupSectionComponent } from './backup-section.component';
import { OpmlSectionComponent } from './opml-section.component';
import { SettingsStackComponent } from '../shared/settings/stack/settings-stack.component';

/**
 * The `/settings/import` page: OPML import/export and account backup, side by
 * side in one stack.
 *
 * It exists because the route used to load `OpmlSectionComponent` and let *it*
 * render `<app-backup-section />` from its own template, purely so both landed
 * on the same page (#454). That made the OPML section own an unrelated feature,
 * and it put a component host boundary between the two cards -- which the
 * global `app-settings-card + app-settings-card` gap could not cross, so the
 * backup card carried a compensating margin. A page whose only job is to
 * compose the two sections costs one file and removes both problems: reordering
 * them, or adding a third, is one line here.
 */
@Component({
  selector: 'app-import-section',
  imports: [BackupSectionComponent, OpmlSectionComponent, SettingsStackComponent],
  templateUrl: './import-section.component.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ImportSectionComponent {}
