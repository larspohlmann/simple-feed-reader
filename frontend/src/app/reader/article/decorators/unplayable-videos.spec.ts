import { attachHlsStreams } from './hls-streams';
import { replaceUnplayableVideo } from './unplayable-videos';

const page = {
  pageUrl: 'https://news.test/story',
  title: 'This video cannot play here',
  action: 'Watch it on the original page',
};

function body(html: string): HTMLElement {
  const element = document.createElement('div');
  element.innerHTML = html;
  return element;
}

describe('replaceUnplayableVideo', () => {
  it('replaces a failed video with a link card to the original page', () => {
    const element = body('<video src="https://cdn.test/clip.mp4" poster="https://cdn.test/p.jpg">');

    replaceUnplayableVideo(element.querySelector('video'), () => page);

    expect(element.querySelector('video')).toBeNull();
    const link = element.querySelector<HTMLAnchorElement>('figure.link-card > a')!;
    expect(link.href).toBe('https://news.test/story');
    expect(link.target).toBe('_blank');
    expect(link.rel).toBe('noopener noreferrer');
    expect(link.querySelector('img')!.getAttribute('src')).toBe('https://cdn.test/p.jpg');
    expect(link.querySelector('strong')!.textContent).toBe(page.title);
    expect(link.querySelector('span')!.textContent).toBe(page.action);
    expect(link.querySelector('small')!.textContent).toBe('news.test');
  });

  it('links the video file itself when the entry has no page', () => {
    const element = body('<video src="https://cdn.test/clip.mp4"></video>');

    replaceUnplayableVideo(element.querySelector('video'), () => ({ ...page, pageUrl: null }));

    const link = element.querySelector<HTMLAnchorElement>('.link-card a')!;
    expect(link.href).toBe('https://cdn.test/clip.mp4');
    expect(link.querySelector('img')).toBeNull();
    expect(link.querySelector('small')!.textContent).toBe('cdn.test');
  });

  it('replaces the video once its last source fails, and not before', () => {
    const element = body(
      '<video><source src="https://cdn.test/a.webm"><source src="https://cdn.test/b.mp4"></video>',
    );
    const [first, last] = Array.from(element.querySelectorAll('source'));

    replaceUnplayableVideo(first, () => page);
    expect(element.querySelector('video')).not.toBeNull();

    replaceUnplayableVideo(last, () => page);
    expect(element.querySelector('video')).toBeNull();
    expect(element.querySelector('.link-card')).not.toBeNull();
  });

  it('links the first source when neither a page nor a src is known', () => {
    const element = body('<video><source src="https://cdn.test/a.mp4"></video>');

    replaceUnplayableVideo(element.querySelector('source'), () => ({ ...page, pageUrl: null }));

    expect(element.querySelector<HTMLAnchorElement>('.link-card a')!.href).toBe(
      'https://cdn.test/a.mp4',
    );
  });

  it('leaves a video hls.js manages to hls.js, which swaps its source on first play', () => {
    const element = body('<video src="https://cdn.test/master.m3u8"></video>');
    attachHlsStreams(element);

    replaceUnplayableVideo(element.querySelector('video'), () => page);

    expect(element.querySelector('video')).not.toBeNull();
  });

  it('ignores errors that are not a video failing', () => {
    const element = body('<img src="https://cdn.test/broken.jpg">');

    replaceUnplayableVideo(element.querySelector('img'), () => page);

    expect(element.querySelector('img')).not.toBeNull();
    expect(element.querySelector('.link-card')).toBeNull();
  });
});
