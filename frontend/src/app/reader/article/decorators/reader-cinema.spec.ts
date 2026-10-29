import { articleHost } from '../../../../testing/article-host-testing';
import { wrapCinemaBoxes } from './reader-cinema';

const LANDSCAPE =
  '<div class="reader-embed reader-embed--landscape"><iframe src="https://www.youtube-nocookie.com/embed/x"></iframe></div>';

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
