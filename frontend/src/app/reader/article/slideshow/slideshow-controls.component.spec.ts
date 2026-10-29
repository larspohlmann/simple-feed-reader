import { Injector, inputBinding } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { articleHost } from '../../../../testing/article-host-testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ArticleIslands } from '../reader-view/article-islands';
import { SlideshowControlsComponent } from './slideshow-controls.component';

const SLIDES =
  '<figure class="reader-slideshow"><ol>' +
  '<li><img src="https://img/1.jpg" alt="one"></li>' +
  '<li><img src="https://img/2.jpg" alt="two"></li>' +
  '<li><img src="https://img/3.jpg" alt="three"></li></ol></figure>';

function mounted(html = SLIDES) {
  TestBed.configureTestingModule({ imports: [provideTranslocoTesting()] });
  const host = articleHost(html);
  const figure = host.querySelector<HTMLElement>('.reader-slideshow')!;
  const slides = Array.from(figure.querySelectorAll<HTMLElement>('li'));
  const islands = new ArticleIslands(TestBed.inject(Injector));
  islands.mountAfter(slides[0].parentElement!, SlideshowControlsComponent, [
    inputBinding('figure', () => figure),
    inputBinding('slides', () => slides),
  ]);
  TestBed.tick();
  return { host, figure, slides, islands };
}

function touch(x: number, y: number, type: string): TouchEvent {
  return Object.assign(new Event(type, { bubbles: true, cancelable: true }), {
    changedTouches: [{ clientX: x, clientY: y }],
  }) as unknown as TouchEvent;
}

const track = (host: HTMLElement): HTMLElement => host.querySelector('.reader-slideshow ol')!;
const next = (host: HTMLElement): HTMLButtonElement =>
  host.querySelector<HTMLButtonElement>('.reader-slideshow__next')!;

describe('SlideshowControlsComponent', () => {
  it('shows the first slide and marks the figure ready', () => {
    const { host, figure, slides } = mounted();
    expect(figure.classList.contains('reader-slideshow--ready')).toBe(true);
    expect(track(host).style.transform).toBe('translateX(0%)');
    expect(slides[0].getAttribute('aria-hidden')).toBe('false');
    expect(slides[1].getAttribute('aria-hidden')).toBe('true');
    expect(host.textContent).toContain('1 of 3');
    expect(host.querySelector('.reader-slideshow__prev')!.textContent?.trim()).toBe('‹');
    expect(next(host).textContent?.trim()).toBe('›');
    expect(host.querySelector('.reader-slideshow__prev')!.getAttribute('aria-label')).toBe(
      'Previous slide',
    );
    expect(next(host).getAttribute('aria-label')).toBe('Next slide');
  });

  it('advances on the next control by translating the track', () => {
    const { host, slides } = mounted();
    next(host).click();
    TestBed.tick();
    expect(track(host).style.transform).toBe('translateX(-100%)');
    expect(slides[0].getAttribute('aria-hidden')).toBe('true');
    expect(slides[1].getAttribute('aria-hidden')).toBe('false');
    expect(host.textContent).toContain('2 of 3');
  });

  it('advances on ArrowRight', () => {
    const { host, figure } = mounted();
    figure.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
    TestBed.tick();
    expect(host.textContent).toContain('2 of 3');
  });

  it('keeps every slide but the current one inert so hidden links leave the tab order', () => {
    const { host, slides } = mounted();
    expect(slides[0].hasAttribute('inert')).toBe(false);
    expect(slides[1].hasAttribute('inert')).toBe(true);

    next(host).click();
    TestBed.tick();
    expect(slides[0].hasAttribute('inert')).toBe(true);
    expect(slides[1].hasAttribute('inert')).toBe(false);
  });

  it('preserves a caption link inside a slide', () => {
    const { host } = mounted(
      '<figure class="reader-slideshow"><ol>' +
        '<li><img src="https://img/1.jpg" alt="one"><a href="https://example.com/a">One</a></li>' +
        '<li><img src="https://img/2.jpg" alt="two"><a href="https://example.com/b">Two</a></li></ol></figure>',
    );
    const link = host.querySelector<HTMLAnchorElement>('.reader-slideshow li a');
    expect(link!.getAttribute('href')).toBe('https://example.com/a');
  });

  it('advances on a leftward swipe', () => {
    const { host, figure } = mounted();
    figure.dispatchEvent(touch(200, 0, 'touchstart'));
    figure.dispatchEvent(touch(120, 0, 'touchend'));
    TestBed.tick();
    expect(host.textContent).toContain('2 of 3');
  });

  it('ignores a mostly-vertical swipe', () => {
    const { host, figure } = mounted();
    figure.dispatchEvent(touch(200, 0, 'touchstart'));
    figure.dispatchEvent(touch(190, 200, 'touchend'));
    TestBed.tick();
    expect(host.textContent).toContain('1 of 3');
  });

  it('claims a horizontal swipe so the page gesture never sees it', () => {
    const parent = document.createElement('div');
    const { host, figure } = mounted();
    parent.append(host);

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
    const { host, figure } = mounted();
    parent.append(host);

    const pageMove = jest.fn();
    parent.addEventListener('touchmove', pageMove);

    figure.dispatchEvent(touch(200, 100, 'touchstart'));
    figure.dispatchEvent(touch(195, 260, 'touchmove')); // vertical-dominant

    expect(pageMove).toHaveBeenCalled();
  });

  it('ignores a non-arrow key', () => {
    const { host, figure } = mounted();
    const event = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
    figure.dispatchEvent(event);
    TestBed.tick();
    expect(event.defaultPrevented).toBe(false);
    expect(host.textContent).toContain('1 of 3');
  });

  it('picks up the slide a previous mount showed', () => {
    const { host, figure, slides, islands } = mounted();
    next(host).click();
    TestBed.tick();
    islands.releaseAll();

    const again = new ArticleIslands(TestBed.inject(Injector));
    again.mountAfter(slides[0].parentElement!, SlideshowControlsComponent, [
      inputBinding('figure', () => figure),
      inputBinding('slides', () => slides),
    ]);
    TestBed.tick();

    expect(host.textContent).toContain('2 of 3');
    expect(track(host).style.transform).toBe('translateX(-100%)');
  });

  it('unbinds its gestures when destroyed', () => {
    const { figure, islands } = mounted();
    islands.releaseAll();
    const event = new KeyboardEvent('keydown', {
      key: 'ArrowRight',
      bubbles: true,
      cancelable: true,
    });
    figure.dispatchEvent(event);
    expect(event.defaultPrevented).toBe(false);
  });
});
