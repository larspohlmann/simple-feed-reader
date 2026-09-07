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

  let current = 0;
  slides.forEach((slide, index) =>
    slide.setAttribute('aria-label', `${index + 1} / ${slides.length}`),
  );

  const counter = document.createElement('span');
  counter.className = 'reader-slideshow__counter';
  counter.setAttribute('aria-live', 'polite');

  const show = (next: number): void => {
    current = (next + slides.length) % slides.length;
    slides.forEach((slide, index) => (slide.hidden = index !== current));
    counter.textContent = labels.position(current + 1, slides.length);
  };

  const previous = control('reader-slideshow__prev', labels.previous, () => show(current - 1));
  const next = control('reader-slideshow__next', labels.next, () => show(current + 1));

  const controls = document.createElement('div');
  controls.className = 'reader-slideshow__controls';
  controls.append(previous, counter, next);
  figure.append(controls);

  figure.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowRight') show(current + 1);
    else if (event.key === 'ArrowLeft') show(current - 1);
    else return;
    event.preventDefault();
  });

  let startX = 0;
  const SWIPE_THRESHOLD = 40;
  figure.addEventListener(
    'touchstart',
    (event) => {
      startX = event.changedTouches[0]?.clientX ?? 0;
    },
    { passive: true },
  );
  figure.addEventListener('touchend', (event) => {
    const deltaX = (event.changedTouches[0]?.clientX ?? 0) - startX;
    if (Math.abs(deltaX) < SWIPE_THRESHOLD) return;
    show(deltaX < 0 ? current + 1 : current - 1);
  });

  show(0);
}

function control(className: string, label: string, onClick: () => void): HTMLButtonElement {
  const button = document.createElement('button');
  button.type = 'button';
  button.className = className;
  button.setAttribute('aria-label', label);
  button.addEventListener('click', onClick);
  return button;
}
