import { ChangeDetectionStrategy, Component, computed, input, output } from '@angular/core';
import { scrubFill } from '../../../audio/scrub-fill';
import { scrubMarks } from '../../../audio/scrub-marks';
import type { ChapterDto } from '../../../models';

/**
 * The seek bar the mini bar and the big player share: a native range for keyboard and
 * screen-reader seeking, the played and cached fill drawn on its track, and a decorative
 * chapter-mark layer over it (#1810). Its size is the host's to set through `--bar`,
 * `--thumb` and `--tap` (see the stylesheet).
 */
@Component({
  selector: 'app-scrubber',
  templateUrl: './scrubber.component.html',
  styleUrl: './scrubber.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ScrubberComponent {
  readonly position = input.required<number>();
  readonly duration = input.required<number>();
  readonly buffered = input.required<number>();
  readonly chapters = input<readonly ChapterDto[]>([]);
  readonly label = input.required<string>();
  readonly seek = output<number>();
  readonly dragging = output<boolean>();

  protected readonly fill = computed(() =>
    scrubFill(this.position(), this.buffered(), this.duration()),
  );
  protected readonly marks = computed(() => scrubMarks(this.chapters(), this.duration()));

  protected onScrub(event: Event): void {
    this.seek.emit((event.target as HTMLInputElement).valueAsNumber);
  }
}
