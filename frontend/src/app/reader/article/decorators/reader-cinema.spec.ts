import { addCinemaToggles, type CinemaLabels } from './reader-cinema';

const labels: CinemaLabels = { enter: 'Cinema', exit: 'Exit cinema' };

function host(html: string): HTMLElement {
  const element = document.createElement('div');
  element.innerHTML = html;
  return element;
}

const LANDSCAPE =
  '<div class="reader-embed"><iframe src="https://www.youtube-nocookie.com/embed/x"></iframe></div>';

describe('addCinemaToggles', () => {
  beforeAll(() => {
    Element.prototype.scrollIntoView = jest.fn();
  });

  it('wraps a landscape embed with a cinema toggle beneath it', () => {
    const element = host(LANDSCAPE);
    addCinemaToggles(element, labels);
    const box = element.querySelector('.reader-cinema')!;
    expect(box.firstElementChild!.classList.contains('reader-embed')).toBe(true);
    const toggle = box.querySelector('.reader-cinema__bar > button.reader-cinema__toggle')!;
    expect(toggle.classList.contains('list-action')).toBe(true);
    expect(toggle.getAttribute('type')).toBe('button');
    expect(toggle.getAttribute('aria-keyshortcuts')).toBe('t');
    expect(toggle.textContent).toBe('width_wideCinemat');
  });

  it('wraps a native video', () => {
    const element = host('<p>Intro</p><video src="https://cdn/clip.mp4"></video>');
    addCinemaToggles(element, labels);
    expect(element.querySelector('.reader-cinema > video')).not.toBeNull();
  });

  it('leaves portrait and tall embeds and audio alone', () => {
    const element = host(
      '<div class="reader-embed reader-embed--portrait"><iframe></iframe></div>' +
        '<div class="reader-embed reader-embed--tall"><iframe></iframe></div>' +
        '<audio src="https://cdn/a.mp3"></audio>',
    );
    addCinemaToggles(element, labels);
    expect(element.querySelector('.reader-cinema')).toBeNull();
  });

  it('is idempotent', () => {
    const element = host(LANDSCAPE);
    addCinemaToggles(element, labels);
    addCinemaToggles(element, labels);
    expect(element.querySelectorAll('.reader-cinema')).toHaveLength(1);
    expect(element.querySelectorAll('.reader-cinema__toggle')).toHaveLength(1);
  });

  it('widens on click, flips the label and icon, and narrows again', () => {
    const element = host(LANDSCAPE);
    addCinemaToggles(element, labels);
    const box = element.querySelector<HTMLElement>('.reader-cinema')!;
    const toggle = element.querySelector<HTMLButtonElement>('.reader-cinema__toggle')!;

    toggle.click();
    expect(box.classList.contains('reader-cinema--on')).toBe(true);
    expect(toggle.textContent).toBe('width_normalExit cinemat');
    expect(box.scrollIntoView).toHaveBeenCalledWith({ block: 'nearest' });

    toggle.click();
    expect(box.classList.contains('reader-cinema--on')).toBe(false);
    expect(toggle.textContent).toBe('width_wideCinemat');
  });
});
