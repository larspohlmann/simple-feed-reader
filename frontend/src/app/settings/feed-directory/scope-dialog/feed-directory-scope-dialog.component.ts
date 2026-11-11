import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { A11yModule } from '@angular/cdk/a11y';
import { DialogRef } from '@angular/cdk/dialog';
import { TranslocoPipe } from '@jsverse/transloco';
import { OverlayPanelComponent } from '../../../shared/overlay-panel/overlay-panel.component';
import { FeedDirectoryScopeService } from '../feed-directory-scope.service';
import { FeedDirectoryScopePickerComponent } from '../scope-picker/feed-directory-scope-picker.component';

@Component({
  selector: 'app-feed-directory-scope-dialog',
  imports: [A11yModule, FeedDirectoryScopePickerComponent, OverlayPanelComponent, TranslocoPipe],
  templateUrl: './feed-directory-scope-dialog.component.html',
  styleUrl: './feed-directory-scope-dialog.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FeedDirectoryScopeDialogComponent {
  protected readonly ref = inject<DialogRef<void>>(DialogRef);
  protected readonly scopes = inject(FeedDirectoryScopeService);
}
