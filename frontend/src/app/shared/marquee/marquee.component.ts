import {
  afterNextRender,
  ChangeDetectionStrategy,
  Component,
  computed,
  contentChild,
  DestroyRef,
  ElementRef,
  inject,
  input,
  signal,
  TemplateRef,
  viewChild,
} from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';

const PIXELS_PER_SECOND = 24;
/** Share of each lap spent moving; must match the 15% hold in the keyframes. */
const MOVING_SHARE = 0.85;

/** One line that loops sideways without end when wider than its box. The line is an
 *  `<ng-template>` child, because the loop renders it twice and projected content renders once. */
@Component({
  selector: 'app-marquee',
  imports: [NgTemplateOutlet],
  template: `
    <span class="track">
      <span #copy class="copy"><ng-container [ngTemplateOutlet]="line()" /></span>
      @if (overflowing()) {
        <span class="copy repeat" aria-hidden="true"
          ><ng-container [ngTemplateOutlet]="line()"
        /></span>
      }
    </span>
  `,
  styleUrl: './marquee.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    '[class.overflowing]': 'overflowing()',
    '[class.paused]': 'paused()',
    '[style.--marquee-copy]': "copyWidth() + 'px'",
    '[style.--marquee-duration]': "duration() + 's'",
  },
})
export class MarqueeComponent {
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly copy = viewChild.required<ElementRef<HTMLElement>>('copy');
  protected readonly line = contentChild.required(TemplateRef);
  /** Holds the line where it is, e.g. while something covers it. */
  readonly paused = input(false);

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
