import { hydrateSlideshows, type SlideshowLabels } from './reader-slideshow';

const labels: SlideshowLabels = {
  previous: 'Previous',
  next: 'Next',
  position: (c, t) => `${c} / ${t}`,
};

function host(): HTMLElement {
  const el = document.createElement('div');
  el.innerHTML =
    '<figure class="reader-slideshow"><ol>' +
    '<li><img src="https://img/1.jpg" alt="one"></li>' +
    '<li><img src="https://img/2.jpg" alt="two"></li>' +
    '<li><img src="https://img/3.jpg" alt="three"></li></ol></figure>';
  return el;
}

describe('hydrateSlideshows', () => {
  it('shows the first slide and marks the figure ready', () => {
    const el = host();
    hydrateSlideshows(el, labels);
    const slides = el.querySelectorAll('.reader-slideshow li');
    expect(
      el.querySelector('.reader-slideshow')!.classList.contains('reader-slideshow--ready'),
    ).toBe(true);
    expect((slides[0] as HTMLElement).hidden).toBe(false);
    expect((slides[1] as HTMLElement).hidden).toBe(true);
    expect(el.textContent).toContain('1 / 3');
    expect(el.querySelector('.reader-slideshow__prev')!.textContent).toBe('‹');
    expect(el.querySelector('.reader-slideshow__next')!.textContent).toBe('›');
    expect(el.querySelector('.reader-slideshow__prev')!.getAttribute('aria-label')).toBe(
      'Previous',
    );
  });

  it('advances on the next control and wraps the counter', () => {
    const el = host();
    hydrateSlideshows(el, labels);
    el.querySelector<HTMLButtonElement>('.reader-slideshow__next')!.click();
    const slides = el.querySelectorAll('.reader-slideshow li');
    expect((slides[0] as HTMLElement).hidden).toBe(true);
    expect((slides[1] as HTMLElement).hidden).toBe(false);
    expect(el.textContent).toContain('2 / 3');
  });

  it('advances on ArrowRight', () => {
    const el = host();
    hydrateSlideshows(el, labels);
    el.querySelector<HTMLElement>('.reader-slideshow')!.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }),
    );
    expect(el.textContent).toContain('2 / 3');
  });

  it('is idempotent', () => {
    const el = host();
    hydrateSlideshows(el, labels);
    hydrateSlideshows(el, labels);
    expect(el.querySelectorAll('.reader-slideshow__next').length).toBe(1);
  });

  it('advances on a leftward swipe', () => {
    const el = host();
    hydrateSlideshows(el, labels);
    const figure = el.querySelector<HTMLElement>('.reader-slideshow')!;
    const touch = (x: number) =>
      ({ changedTouches: [{ clientX: x, clientY: 0 }] }) as unknown as TouchEvent;
    figure.dispatchEvent(Object.assign(new Event('touchstart'), touch(200)));
    figure.dispatchEvent(Object.assign(new Event('touchend'), touch(120)));
    expect(el.textContent).toContain('2 / 3');
  });

  it('ignores a non-arrow key', () => {
    const el = host();
    hydrateSlideshows(el, labels);
    const figure = el.querySelector<HTMLElement>('.reader-slideshow')!;
    const event = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
    figure.dispatchEvent(event);
    expect(event.defaultPrevented).toBe(false);
    expect(el.textContent).toContain('1 / 3');
  });

  it('leaves a single-slide figure un-hydrated', () => {
    const el = document.createElement('div');
    el.innerHTML =
      '<figure class="reader-slideshow"><ol>' +
      '<li><img src="https://img/1.jpg" alt="one"></li></ol></figure>';
    hydrateSlideshows(el, labels);
    const figure = el.querySelector<HTMLElement>('.reader-slideshow')!;
    expect(figure.classList.contains('reader-slideshow--ready')).toBe(false);
    expect(figure.querySelector('.reader-slideshow__controls')).toBeNull();
  });
});
