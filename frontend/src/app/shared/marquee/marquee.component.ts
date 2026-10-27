import {
  afterNextRender,
  ChangeDetectionStrategy,
  Component,
  computed,
  contentChild,
  DestroyRef,
  ElementRef,
  inject,
  signal,
  TemplateRef,
  viewChild,
} from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';

const PIXELS_PER_SECOND = 24;
/** Share of each lap spent moving; the rest holds the start still so it can be read. */
const MOVING_SHARE = 0.85;

/**
 * One line of text that, when wider than its box, loops sideways without end: a second
 * copy follows the first, so the line's end runs straight into its start again.
 * The line is an `<ng-template>` child, as projected content can be rendered only once.
 */
@Component({
  selector: 'app-marquee',
  imports: [NgTemplateOutlet],
  template: `
    <span #copy class="copy"><ng-container [ngTemplateOutlet]="line()" /></span>
    @if (overflowing()) {
      <span class="copy repeat" aria-hidden="true"
        ><ng-container [ngTemplateOutlet]="line()"
      /></span>
    }
  `,
  styleUrl: './marquee.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    '[class.overflowing]': 'overflowing()',
    '[style.--marquee-copy]': "copyWidth() + 'px'",
    '[style.--marquee-duration]': "duration() + 's'",
  },
})
export class MarqueeComponent {
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly copy = viewChild.required<ElementRef<HTMLElement>>('copy');
  protected readonly line = contentChild.required(TemplateRef);

  protected readonly copyWidth = signal(0);
  private readonly available = signal(0);
  protected readonly overflowing = computed(() => this.copyWidth() > this.available());
  protected readonly duration = computed(
    () => Math.round((this.copyWidth() / PIXELS_PER_SECOND / MOVING_SHARE) * 10) / 10,
  );

  constructor() {
    const destroyRef = inject(DestroyRef);
    afterNextRender(() => {
      if (typeof ResizeObserver === 'undefined') {
        return;
      }
      const observer = new ResizeObserver(() => this.measure());
      observer.observe(this.host.nativeElement);
      observer.observe(this.copy().nativeElement);
      destroyRef.onDestroy(() => observer.disconnect());
    });
  }

  private measure(): void {
    this.available.set(this.host.nativeElement.clientWidth);
    this.copyWidth.set(Math.ceil(this.copy().nativeElement.getBoundingClientRect().width));
  }
}
