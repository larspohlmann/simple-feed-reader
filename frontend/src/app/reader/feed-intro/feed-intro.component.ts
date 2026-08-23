import { ChangeDetectionStrategy, Component, computed, input, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';

/**
 * What a feed says about itself: its own image, its description and a link to
 * its site. Shown once, at the top of the entry list, when the reader is
 * scoped to a single feed (#568).
 *
 * It is rendered through the list's `topBlock` outlet rather than inside the
 * list header, so it scrolls away with the rows. The header is sticky and
 * collapses on scroll; making it taller moves the rows under the finger (#419).
 */
@Component({
  selector: 'app-feed-intro',
  imports: [IconComponent, TranslocoPipe],
  templateUrl: './feed-intro.component.html',
  styleUrl: './feed-intro.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FeedIntroComponent {
  readonly description = input<string | null>(null);
  /** Plain https URL, already flattened and capped by the API. */
  readonly imageUrl = input<string | null>(null);
  readonly siteUrl = input<string | null>(null);

  /** A dead logo URL must leave no broken-image box, so the <img> is dropped
   *  rather than left to render its own failure. Mirrors app-favicon. */
  protected readonly broken = signal(false);
  protected readonly src = computed(() => (this.broken() ? null : this.imageUrl()));

  /** Whether there is anything at all to show. The caller uses this to leave
   *  the block out entirely rather than render an empty box above the rows. */
  readonly hasContent = computed(
    () => this.description() !== null || this.imageUrl() !== null || this.siteUrl() !== null,
  );
}
