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

function touch(x: number, y: number, type: string): TouchEvent {
  return Object.assign(new Event(type, { bubbles: true, cancelable: true }), {
    changedTouches: [{ clientX: x, clientY: y }],
  }) as unknown as TouchEvent;
}

describe('hydrateSlideshows', () => {
  it('shows the first slide and marks the figure ready', () => {
    const el = host();
    hydrateSlideshows(el, labels);
    const slides = el.querySelectorAll('.reader-slideshow li');
    expect(
      el.querySelector('.reader-slideshow')!.classList.contains('reader-slideshow--ready'),
    ).toBe(true);
    expect((el.querySelector('.reader-slideshow ol') as HTMLElement).style.transform).toBe(
      'translateX(0%)',
    );
    expect(slides[0].getAttribute('aria-hidden')).toBe('false');
    expect(slides[1].getAttribute('aria-hidden')).toBe('true');
    expect(el.textContent).toContain('1 / 3');
    expect(el.querySelector('.reader-slideshow__prev')!.textContent).toBe('‹');
    expect(el.querySelector('.reader-slideshow__next')!.textContent).toBe('›');
    expect(el.querySelector('.reader-slideshow__prev')!.getAttribute('aria-label')).toBe(
      'Previous',
    );
  });

  it('advances on the next control by translating the track', () => {
    const el = host();
    hydrateSlideshows(el, labels);
    el.querySelector<HTMLButtonElement>('.reader-slideshow__next')!.click();
    const slides = el.querySelectorAll('.reader-slideshow li');
    expect((el.querySelector('.reader-slideshow ol') as HTMLElement).style.transform).toBe(
      'translateX(-100%)',
    );
    expect(slides[0].getAttribute('aria-hidden')).toBe('true');
    expect(slides[1].getAttribute('aria-hidden')).toBe('false');
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
    figure.dispatchEvent(touch(200, 0, 'touchstart'));
    figure.dispatchEvent(touch(120, 0, 'touchend'));
    expect(el.textContent).toContain('2 / 3');
  });

  it('ignores a mostly-vertical swipe', () => {
    const el = host();
    hydrateSlideshows(el, labels);
    const figure = el.querySelector<HTMLElement>('.reader-slideshow')!;
    figure.dispatchEvent(touch(200, 0, 'touchstart'));
    figure.dispatchEvent(touch(190, 200, 'touchend'));
    expect(el.textContent).toContain('1 / 3');
  });

  it('claims a horizontal swipe so the page gesture never sees it', () => {
    const parent = document.createElement('div');
    const el = host();
    parent.append(el);
    hydrateSlideshows(el, labels);
    const figure = el.querySelector<HTMLElement>('.reader-slideshow')!;

    const pageMove = jest.fn();
    const pageEnd = jest.fn();
    parent.addEventListener('touchmove', pageMove);
    parent.addEventListener('touchend', pageEnd);

    figure.dispatchEvent(touch(200, 100, 'touchstart'));
    const move = touch(120, 105, 'touchmove'); // horizontal-dominant
    figure.dispatchEvent(move);
    figure.dispatchEvent(touch(110, 105, 'touchend'));

    expect(pageMove).not.toHaveBeenCalled();
    expect(pageEnd).not.toHaveBeenCalled();
    expect(move.defaultPrevented).toBe(true);
  });

  it('lets a vertical drag bubble to the page so the article can scroll', () => {
    const parent = document.createElement('div');
    const el = host();
    parent.append(el);
    hydrateSlideshows(el, labels);
    const figure = el.querySelector<HTMLElement>('.reader-slideshow')!;

    const pageMove = jest.fn();
    parent.addEventListener('touchmove', pageMove);

    figure.dispatchEvent(touch(200, 100, 'touchstart'));
    figure.dispatchEvent(touch(195, 260, 'touchmove')); // vertical-dominant

    expect(pageMove).toHaveBeenCalled();
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
