/**
 * Upgrades a backend-emitted `<figure class="reader-slideshow">` (a captioned
 * vertical image stack that already works with no JavaScript) into a swipeable,
 * keyboard-navigable carousel showing one slide at a time. Idempotent: a figure
 * already carrying `reader-slideshow--ready` is left alone, because the reader's
 * enhancement effect re-runs on the Reader/Original toggle.
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
