import { hydrateSlideshows, type SlideshowLabels } from './reader-slideshow';

const labels: SlideshowLabels = {
  previous: 'Previous',
  next: 'Next',
  position: (current, total) => `${current} / ${total}`,
};

function host(): HTMLElement {
  const element = document.createElement('div');
  element.innerHTML =
    '<figure class="reader-slideshow"><ol>' +
    '<li><img src="https://img/1.jpg" alt="one"></li>' +
    '<li><img src="https://img/2.jpg" alt="two"></li>' +
    '<li><img src="https://img/3.jpg" alt="three"></li></ol></figure>';
  return element;
}

function touch(x: number, y: number, type: string): TouchEvent {
  return Object.assign(new Event(type, { bubbles: true, cancelable: true }), {
    changedTouches: [{ clientX: x, clientY: y }],
  }) as unknown as TouchEvent;
}

describe('hydrateSlideshows', () => {
  it('shows the first slide and marks the figure ready', () => {
    const element = host();
    hydrateSlideshows(element, labels);
    const slides = element.querySelectorAll('.reader-slideshow li');
    expect(
      element.querySelector('.reader-slideshow')!.classList.contains('reader-slideshow--ready'),
    ).toBe(true);
    expect((element.querySelector('.reader-slideshow ol') as HTMLElement).style.transform).toBe(
      'translateX(0%)',
    );
    expect(slides[0].getAttribute('aria-hidden')).toBe('false');
    expect(slides[1].getAttribute('aria-hidden')).toBe('true');
    expect(element.textContent).toContain('1 / 3');
    expect(element.querySelector('.reader-slideshow__prev')!.textContent).toBe('‹');
    expect(element.querySelector('.reader-slideshow__next')!.textContent).toBe('›');
    expect(element.querySelector('.reader-slideshow__prev')!.getAttribute('aria-label')).toBe(
      'Previous',
    );
  });

  it('advances on the next control by translating the track', () => {
    const element = host();
    hydrateSlideshows(element, labels);
    element.querySelector<HTMLButtonElement>('.reader-slideshow__next')!.click();
    const slides = element.querySelectorAll('.reader-slideshow li');
    expect((element.querySelector('.reader-slideshow ol') as HTMLElement).style.transform).toBe(
      'translateX(-100%)',
    );
    expect(slides[0].getAttribute('aria-hidden')).toBe('true');
    expect(slides[1].getAttribute('aria-hidden')).toBe('false');
    expect(element.textContent).toContain('2 / 3');
  });

  it('advances on ArrowRight', () => {
    const element = host();
    hydrateSlideshows(element, labels);
    element
      .querySelector<HTMLElement>('.reader-slideshow')!
      .dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
    expect(element.textContent).toContain('2 / 3');
  });

  it('keeps every slide but the current one inert so hidden links leave the tab order', () => {
    const element = host();
    hydrateSlideshows(element, labels);
    const slides = element.querySelectorAll('.reader-slideshow li');
    expect(slides[0].hasAttribute('inert')).toBe(false);
    expect(slides[1].hasAttribute('inert')).toBe(true);

    element.querySelector<HTMLButtonElement>('.reader-slideshow__next')!.click();
    expect(slides[0].hasAttribute('inert')).toBe(true);
    expect(slides[1].hasAttribute('inert')).toBe(false);
  });

  it('preserves a caption link inside a slide', () => {
    const element = document.createElement('div');
    element.innerHTML =
      '<figure class="reader-slideshow"><ol>' +
      '<li><img src="https://img/1.jpg" alt="one"><a href="https://example.com/a">One</a></li>' +
      '<li><img src="https://img/2.jpg" alt="two"><a href="https://example.com/b">Two</a></li></ol></figure>';
    hydrateSlideshows(element, labels);
    const link = element.querySelector<HTMLAnchorElement>('.reader-slideshow li a');
    expect(link!.getAttribute('href')).toBe('https://example.com/a');
  });

  it('is idempotent', () => {
    const element = host();
    hydrateSlideshows(element, labels);
    hydrateSlideshows(element, labels);
    expect(element.querySelectorAll('.reader-slideshow__next').length).toBe(1);
  });

  it('advances on a leftward swipe', () => {
    const element = host();
    hydrateSlideshows(element, labels);
    const figure = element.querySelector<HTMLElement>('.reader-slideshow')!;
    figure.dispatchEvent(touch(200, 0, 'touchstart'));
    figure.dispatchEvent(touch(120, 0, 'touchend'));
    expect(element.textContent).toContain('2 / 3');
  });

  it('ignores a mostly-vertical swipe', () => {
    const element = host();
    hydrateSlideshows(element, labels);
    const figure = element.querySelector<HTMLElement>('.reader-slideshow')!;
    figure.dispatchEvent(touch(200, 0, 'touchstart'));
    figure.dispatchEvent(touch(190, 200, 'touchend'));
    expect(element.textContent).toContain('1 / 3');
  });

  it('claims a horizontal swipe so the page gesture never sees it', () => {
    const parent = document.createElement('div');
    const element = host();
    parent.append(element);
    hydrateSlideshows(element, labels);
    const figure = element.querySelector<HTMLElement>('.reader-slideshow')!;

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
    const element = host();
    parent.append(element);
    hydrateSlideshows(element, labels);
    const figure = element.querySelector<HTMLElement>('.reader-slideshow')!;

    const pageMove = jest.fn();
    parent.addEventListener('touchmove', pageMove);

    figure.dispatchEvent(touch(200, 100, 'touchstart'));
    figure.dispatchEvent(touch(195, 260, 'touchmove')); // vertical-dominant

    expect(pageMove).toHaveBeenCalled();
  });

  it('ignores a non-arrow key', () => {
    const element = host();
    hydrateSlideshows(element, labels);
    const figure = element.querySelector<HTMLElement>('.reader-slideshow')!;
    const event = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
    figure.dispatchEvent(event);
    expect(event.defaultPrevented).toBe(false);
    expect(element.textContent).toContain('1 / 3');
  });

  it('leaves a single-slide figure un-hydrated', () => {
    const element = document.createElement('div');
    element.innerHTML =
      '<figure class="reader-slideshow"><ol>' +
      '<li><img src="https://img/1.jpg" alt="one"></li></ol></figure>';
    hydrateSlideshows(element, labels);
    const figure = element.querySelector<HTMLElement>('.reader-slideshow')!;
    expect(figure.classList.contains('reader-slideshow--ready')).toBe(false);
    expect(figure.querySelector('.reader-slideshow__controls')).toBeNull();
  });
});
