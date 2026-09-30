import { AXIS_LOCK_MIN, isSlideSwipe } from '../../reader-gestures';

/**
 * Upgrades a backend-emitted `<figure class="reader-slideshow">` into a
 * swipeable, keyboard-navigable carousel. Idempotent.
 */
export interface SlideshowLabels {
  previous: string;
  next: string;
  position: (current: number, total: number) => string;
}

export function hydrateSlideshows(host: HTMLElement, labels: SlideshowLabels): void {
  for (const figure of Array.from(host.querySelectorAll<HTMLElement>('.reader-slideshow'))) {
    if (figure.classList.contains('reader-slideshow--ready')) continue;
    const slides = Array.from(figure.querySelectorAll<HTMLElement>('li'));
    if (slides.length < 2) continue;
    build(figure, slides, labels);
  }
}

function build(figure: HTMLElement, slides: HTMLElement[], labels: SlideshowLabels): void {
  figure.classList.add('reader-slideshow--ready');
  figure.setAttribute('aria-roledescription', 'carousel');
  figure.tabIndex = 0;

  slides.forEach((slide, index) =>
    slide.setAttribute('aria-label', `${index + 1} / ${slides.length}`),
  );

  const counter = document.createElement('span');
  counter.className = 'reader-slideshow__counter';
  counter.setAttribute('aria-live', 'polite');

  const track = slides[0].parentElement as HTMLElement;
  const step = slideStepper({ slides, track, counter, labels });

  figure.append(controlBar(labels, counter, step));
  bindArrowKeys(figure, step);
  bindSwipe(figure, step);

  step(0);
}

interface SlideshowParts {
  slides: HTMLElement[];
  track: HTMLElement;
  counter: HTMLElement;
  labels: SlideshowLabels;
}

function slideStepper({ slides, track, counter, labels }: SlideshowParts): (delta: number) => void {
  let current = 0;
  return (delta) => {
    current = (current + delta + slides.length) % slides.length;
    // Slide the track; CSS transitions the transform (instant under reduced motion).
    track.style.transform = `translateX(${current * -100}%)`;
    slides.forEach((slide, index) => {
      const hidden = index !== current;
      slide.setAttribute('aria-hidden', String(hidden));
      // A caption link in an off-screen slide must leave the tab order, or an
      // aria-hidden slide would hold a focusable element (an ARIA violation).
      slide.toggleAttribute('inert', hidden);
    });
    counter.textContent = labels.position(current + 1, slides.length);
  };
}

function controlBar(
  labels: SlideshowLabels,
  counter: HTMLElement,
  step: (delta: number) => void,
): HTMLElement {
  const previous = control({
    className: 'reader-slideshow__prev',
    glyph: '‹',
    label: labels.previous,
    onClick: () => step(-1),
  });
  const next = control({
    className: 'reader-slideshow__next',
    glyph: '›',
    label: labels.next,
    onClick: () => step(1),
  });

  const controls = document.createElement('div');
  controls.className = 'reader-slideshow__controls';
  controls.append(previous, counter, next);
  return controls;
}

function bindArrowKeys(figure: HTMLElement, step: (delta: number) => void): void {
  figure.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowRight') step(1);
    else if (event.key === 'ArrowLeft') step(-1);
    else return;
    event.preventDefault();
  });
}

// The reader body is a horizontally-swipeable "back to list" surface, so the
// carousel claims horizontal drags (stop them reaching it) while letting a
// vertical drag bubble, so the article still scrolls when dragging on an image.
function bindSwipe(figure: HTMLElement, step: (delta: number) => void): void {
  let startX = 0;
  let startY = 0;
  let axis: 'none' | 'horizontal' | 'vertical' = 'none';
  figure.addEventListener(
    'touchstart',
    (event) => {
      startX = event.changedTouches[0]?.clientX ?? 0;
      startY = event.changedTouches[0]?.clientY ?? 0;
      axis = 'none';
    },
    { passive: true },
  );
  figure.addEventListener(
    'touchmove',
    (event) => {
      const dx = (event.changedTouches[0]?.clientX ?? 0) - startX;
      const dy = (event.changedTouches[0]?.clientY ?? 0) - startY;
      if (axis === 'none') {
        if (Math.abs(dx) < AXIS_LOCK_MIN && Math.abs(dy) < AXIS_LOCK_MIN) return;
        axis = Math.abs(dx) > Math.abs(dy) ? 'horizontal' : 'vertical';
      }
      if (axis === 'horizontal') {
        event.stopPropagation();
        event.preventDefault();
      }
    },
    { passive: false },
  );
  figure.addEventListener('touchend', (event) => {
    const direction = isSlideSwipe(
      (event.changedTouches[0]?.clientX ?? 0) - startX,
      (event.changedTouches[0]?.clientY ?? 0) - startY,
    );
    if (direction !== 0) {
      step(direction);
      event.stopPropagation();
    }
    axis = 'none';
  });
}

interface ControlSpec {
  className: string;
  glyph: string;
  label: string;
  onClick: () => void;
}

function control(spec: ControlSpec): HTMLButtonElement {
  const button = document.createElement('button');
  button.type = 'button';
  button.className = spec.className;
  button.textContent = spec.glyph;
  button.setAttribute('aria-label', spec.label);
  button.addEventListener('click', spec.onClick);
  return button;
}
