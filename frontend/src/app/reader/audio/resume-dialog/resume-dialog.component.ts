import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { A11yModule } from '@angular/cdk/a11y';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';
import { TranslocoPipe } from '@jsverse/transloco';
import { ButtonComponent } from '../../../shared/button/button.component';
import { OverlayPanelComponent } from '../../../shared/overlay-panel/overlay-panel.component';
import { formatDuration } from '../../format';

export type ResumeChoice = 'continue' | 'fromStart';

export interface ResumeData {
  title: string;
  seconds: number;
  durationSeconds: number;
}

/** Asks whether a long track continues where it was left or starts over (#1866). */
@Component({
  selector: 'app-resume-dialog',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [A11yModule, TranslocoPipe, ButtonComponent, OverlayPanelComponent],
  templateUrl: './resume-dialog.component.html',
  styleUrl: './resume-dialog.component.scss',
})
export class ResumeDialogComponent {
  readonly ref = inject<DialogRef<ResumeChoice>>(DialogRef);
  readonly data = inject<ResumeData>(DIALOG_DATA);
  readonly position = formatDuration(this.data.seconds);
  readonly duration = formatDuration(this.data.durationSeconds);
}
