import { addCinemaToggles, toggleCinemaByKey, type CinemaLabels } from './reader-cinema';

const labels: CinemaLabels = { enter: 'Cinema mode', exit: 'Exit cinema mode' };

function host(html: string): HTMLElement {
  const element = document.createElement('div');
  element.innerHTML = html;
  return element;
}

const LANDSCAPE =
  '<div class="reader-embed reader-embed--landscape"><iframe src="https://www.youtube-nocookie.com/embed/x"></iframe></div>';

beforeAll(() => {
  Element.prototype.scrollIntoView = jest.fn();
});

describe('addCinemaToggles', () => {
  it('wraps a landscape embed with a cinema toggle beneath it', () => {
    const element = host(LANDSCAPE);
    addCinemaToggles(element, labels);
    const box = element.querySelector('.reader-cinema')!;
    expect(box.firstElementChild!.classList.contains('reader-embed')).toBe(true);
    const toggle = box.querySelector('.reader-cinema__bar > button.reader-cinema__toggle')!;
    expect(toggle.getAttribute('type')).toBe('button');
    expect(toggle.getAttribute('aria-keyshortcuts')).toBe('t');
    expect(toggle.textContent).toBe('width_wideCinema mode(t)');
  });

  it('wraps a native video', () => {
    const element = host('<p>Intro</p><video src="https://cdn/clip.mp4"></video>');
    addCinemaToggles(element, labels);
    expect(element.querySelector('.reader-cinema > video')).not.toBeNull();
  });

  it('leaves portrait, tall and audio embeds and native audio alone', () => {
    const element = host(
      '<div class="reader-embed reader-embed--portrait"><iframe></iframe></div>' +
        '<div class="reader-embed reader-embed--tall"><iframe></iframe></div>' +
        '<div class="reader-embed reader-embed--landscape reader-embed--audio"><iframe></iframe></div>' +
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
    expect(toggle.textContent).toBe('width_normalExit cinema mode(t)');
    expect(box.scrollIntoView).toHaveBeenCalledWith({ block: 'nearest' });

    toggle.click();
    expect(box.classList.contains('reader-cinema--on')).toBe(false);
    expect(toggle.textContent).toBe('width_wideCinema mode(t)');
  });
});

function key(init: KeyboardEventInit = {}, target?: HTMLElement): KeyboardEvent {
  const event = new KeyboardEvent('keydown', { key: 't', cancelable: true, ...init });
  if (target) Object.defineProperty(event, 'target', { value: target });
  return event;
}

function twoVideos(): HTMLElement {
  const element = host(LANDSCAPE + LANDSCAPE);
  addCinemaToggles(element, labels);
  return element;
}

const widened = (element: HTMLElement) =>
  Array.from(element.querySelectorAll('.reader-cinema'), (box) =>
    box.classList.contains('reader-cinema--on'),
  );

describe('toggleCinemaByKey', () => {
  it('toggles the first video when none is in view', () => {
    const element = twoVideos();
    const event = key();
    toggleCinemaByKey(event, element);
    expect(widened(element)).toEqual([true, false]);
    expect(event.defaultPrevented).toBe(true);
  });

  it('toggles the video in view', () => {
    const element = twoVideos();
    const second = element.querySelectorAll<HTMLElement>('.reader-cinema__toggle')[1];
    second.getBoundingClientRect = () => ({ top: 100, bottom: 400 }) as DOMRect;
    toggleCinemaByKey(key(), element);
    expect(widened(element)).toEqual([false, true]);
  });

  it.each([{ metaKey: true }, { ctrlKey: true }, { altKey: true }, { key: 'T' }, { key: 'x' }])(
    'ignores %o',
    (init) => {
      const element = twoVideos();
      toggleCinemaByKey(key(init), element);
      expect(widened(element)).toEqual([false, false]);
    },
  );

  it.each(['input', 'select'])('ignores a key typed into a %s', (tagName) => {
    const element = twoVideos();
    toggleCinemaByKey(key({}, document.createElement(tagName)), element);
    expect(widened(element)).toEqual([false, false]);
  });

  it('does nothing where cinema is not offered', () => {
    const element = twoVideos();
    element.querySelectorAll<HTMLElement>('.reader-cinema__bar').forEach((bar) => {
      bar.style.display = 'none';
    });
    const event = key();
    toggleCinemaByKey(event, element);
    expect(widened(element)).toEqual([false, false]);
    expect(event.defaultPrevented).toBe(false);
  });

  it('does nothing without an article', () => {
    expect(() => toggleCinemaByKey(key(), undefined)).not.toThrow();
  });
});
