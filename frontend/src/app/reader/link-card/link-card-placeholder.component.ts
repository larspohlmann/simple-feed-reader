import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  ElementRef,
  afterNextRender,
  computed,
  inject,
  input,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { PreviewProgress } from './preview-progress';

/** Holds a link card's place while its post is still being completed (#1502), stops claiming
 *  progress once nothing is working on it any more (#1516), and hurries it along once seen
 *  (#1646). */
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
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;
  protected readonly loading = computed(() => this.progress.isLoading(this.entryId()));

  constructor() {
    let sighting: IntersectionObserver | undefined;
    afterNextRender(() => {
      if (typeof IntersectionObserver === 'undefined') return;
      sighting = new IntersectionObserver((changes) => {
        if (!changes.some((change) => change.isIntersecting)) return;
        sighting?.disconnect();
        this.progress.sighted(this.entryId());
      });
      sighting.observe(this.host);
    });
    inject(DestroyRef).onDestroy(() => sighting?.disconnect());
  }
}
