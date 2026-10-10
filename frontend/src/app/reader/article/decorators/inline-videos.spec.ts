import { playVideosInline } from './inline-videos';

describe('playVideosInline', () => {
  it('lets every video in the body play inline, whatever its source', () => {
    const element = document.createElement('div');
    element.innerHTML =
      '<video src="https://x.test/master.m3u8"></video>' +
      '<figure><video controls src="https://x.test/clip.mp4"></video></figure>';

    playVideosInline(element);

    const videos = Array.from(element.querySelectorAll('video'));
    expect(videos).toHaveLength(2);
    for (const video of videos) {
      expect(video.hasAttribute('playsinline')).toBe(true);
    }
  });
});
