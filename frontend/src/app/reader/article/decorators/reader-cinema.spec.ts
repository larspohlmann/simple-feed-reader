import { articleHost } from '../../../../testing/article-host-testing';
import { toggleCinemaByKey, wrapCinemaBoxes } from './reader-cinema';

const LANDSCAPE =
  '<div class="reader-embed reader-embed--landscape"><iframe src="https://www.youtube-nocookie.com/embed/x"></iframe></div>';

const BOXED = `<div class="reader-cinema">${LANDSCAPE}<button class="reader-cinema__toggle"></button></div>`;

beforeAll(() => {
  Element.prototype.scrollIntoView = jest.fn();
});

describe('wrapCinemaBoxes', () => {
  it('wraps a landscape embed in a box holding only the player', () => {
    const element = articleHost(LANDSCAPE);
    wrapCinemaBoxes(element);
    const box = element.querySelector('.reader-cinema')!;
    expect(box.children).toHaveLength(1);
    expect(box.firstElementChild!.classList.contains('reader-embed')).toBe(true);
  });

  it('wraps a native video', () => {
    const element = articleHost('<p>Intro</p><video src="https://cdn/clip.mp4"></video>');
    wrapCinemaBoxes(element);
    expect(element.querySelector('.reader-cinema > video')).not.toBeNull();
  });

  it('leaves portrait, tall and audio embeds and native audio alone', () => {
    const element = articleHost(
      '<div class="reader-embed reader-embed--portrait"><iframe></iframe></div>' +
        '<div class="reader-embed reader-embed--tall"><iframe></iframe></div>' +
        '<div class="reader-embed reader-embed--landscape reader-embed--audio"><iframe></iframe></div>' +
        '<audio src="https://cdn/a.mp3"></audio>',
    );
    wrapCinemaBoxes(element);
    expect(element.querySelector('.reader-cinema')).toBeNull();
  });

  it('is idempotent', () => {
    const element = articleHost(LANDSCAPE);
    wrapCinemaBoxes(element);
    wrapCinemaBoxes(element);
    expect(element.querySelectorAll('.reader-cinema')).toHaveLength(1);
  });
});

function key(init: KeyboardEventInit = {}, target?: HTMLElement): KeyboardEvent {
  const event = new KeyboardEvent('keydown', { key: 't', cancelable: true, ...init });
  if (target) Object.defineProperty(event, 'target', { value: target });
  return event;
}

function twoVideos(): HTMLElement {
  const element = articleHost(BOXED + BOXED);
  element.querySelectorAll<HTMLElement>('.reader-cinema').forEach((box) => {
    box
      .querySelector('.reader-cinema__toggle')!
      .addEventListener('click', () => box.classList.toggle('reader-cinema--on'));
  });
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
    element.querySelectorAll<HTMLElement>('.reader-cinema__toggle').forEach((toggle) => {
      toggle.style.display = 'none';
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
