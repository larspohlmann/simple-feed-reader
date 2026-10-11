import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { PreviewProgress } from './preview-progress';

/** Holds a link card's place while its post is still being completed (#1502), and stops
 *  claiming progress once nothing is working on it any more (#1516). */
@Component({
  selector: 'app-link-card-placeholder',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslocoPipe],
  templateUrl: './link-card-placeholder.component.html',
  styleUrl: './link-card-placeholder.component.scss',
  host: { '[class.loading]': 'loading()', '[attr.aria-busy]': 'loading() || null' },
})
export class LinkCardPlaceholderComponent {
  readonly entryId = input.required<number>();
  private readonly progress = inject(PreviewProgress);
  protected readonly loading = computed(() => this.progress.isLoading(this.entryId()));
}
