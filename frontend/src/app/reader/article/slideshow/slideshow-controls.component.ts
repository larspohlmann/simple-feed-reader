import {
  ChangeDetectionStrategy,
  Component,
  NgZone,
  effect,
  inject,
  input,
  linkedSignal,
  untracked,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { bindSlideGestures } from './slide-gestures';

const READY = 'reader-slideshow--ready';

@Component({
  selector: 'app-slideshow-controls',
  imports: [TranslocoPipe],
  templateUrl: './slideshow-controls.component.html',
  styleUrl: './slideshow-controls.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SlideshowControlsComponent {
  private readonly zone = inject(NgZone);
  readonly figure = input.required<HTMLElement>();
  readonly slides = input.required<readonly HTMLElement[]>();

  protected readonly current = linkedSignal(() =>
    Math.max(
      0,
      this.slides().findIndex((slide) => slide.getAttribute('aria-hidden') === 'false'),
    ),
  );

  constructor() {
    effect((onCleanup) => {
      const figure = this.figure();
      const slides = this.slides();
      untracked(() => markReady(figure, slides));
      onCleanup(
        this.zone.runOutsideAngular(() => bindSlideGestures(figure, (delta) => this.step(delta))),
      );
    });
    effect(() => show(this.slides(), this.current()));
  }

  protected step(delta: number): void {
    const total = this.slides().length;
    this.current.update((index) => (index + delta + total) % total);
  }
}

function markReady(figure: HTMLElement, slides: readonly HTMLElement[]): void {
  figure.classList.add(READY);
  figure.setAttribute('aria-roledescription', 'carousel');
  figure.tabIndex = 0;
  slides.forEach((slide, index) =>
    slide.setAttribute('aria-label', `${index + 1} / ${slides.length}`),
  );
}

function show(slides: readonly HTMLElement[], current: number): void {
  const track = slides[0]?.parentElement;
  if (track) track.style.transform = `translateX(${current * -100}%)`;
  slides.forEach((slide, index) => {
    const hidden = index !== current;
    slide.setAttribute('aria-hidden', String(hidden));
    // A caption link in an off-screen slide must leave the tab order, or an
    // aria-hidden slide would hold a focusable element (an ARIA violation).
    slide.toggleAttribute('inert', hidden);
  });
}
