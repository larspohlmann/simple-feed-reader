import { playVideosInline } from './inline-videos';

function host(html: string): HTMLElement {
  const element = document.createElement('div');
  element.innerHTML = html;
  return element;
}

describe('playVideosInline', () => {
  it('lets every video in the body play inline, whatever its source', () => {
    const element = host(
      '<video src="https://x.test/master.m3u8"></video>' +
        '<figure><video controls src="https://x.test/clip.mp4"></video></figure>',
    );

    playVideosInline(element);

    const videos = Array.from(element.querySelectorAll('video'));
    expect(videos).toHaveLength(2);
    for (const video of videos) {
      expect(video.hasAttribute('playsinline')).toBe(true);
    }
  });

  it('leaves a body without video untouched', () => {
    const element = host('<p>Text only.</p>');

    playVideosInline(element);

    expect(element.innerHTML).toBe('<p>Text only.</p>');
  });
});
