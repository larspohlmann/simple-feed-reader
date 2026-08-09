// src/app/reader/for-you-info-dialog.component.ts
import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { DialogRef } from '@angular/cdk/dialog';
import { TranslocoPipe } from '@jsverse/transloco';
import { ButtonComponent } from '../shared/button/button.component';
import { OverlayPanelComponent } from '../shared/overlay-panel/overlay-panel.component';
import { RecommendationsService } from './recommendations.service';
import { bytesToKb } from './format';

/**
 * What used to be inline hints in the for-you bar (#321): keep-open vs
 * background copy, and the streamed-KB liveness line. Moved behind an info
 * icon so the bar itself carries only the button and, while running, the
 * terse progress line.
 */
@Component({
  selector: 'app-for-you-info-dialog',
  imports: [OverlayPanelComponent, ButtonComponent, TranslocoPipe],
  templateUrl: './for-you-info-dialog.component.html',
  styleUrl: './for-you-info-dialog.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ForYouInfoDialogComponent {
  private readonly ref = inject(DialogRef);
  readonly recs = inject(RecommendationsService);

  /** Bytes of the in-flight provider answer, shown only while there is
   *  something to report -- 0 (shown as null) between calls, since the
   *  server resets the counter when a call ends. Reuses the rounding helper
   *  shared with the recommendation debug log (#309) rather than duplicating
   *  it here. */
  readonly streamedKb = computed(() => {
    const chars = this.recs.report()?.streamedChars ?? 0;
    return chars > 0 ? bytesToKb(chars) : null;
  });

  close(): void {
    this.ref.close();
  }
}
