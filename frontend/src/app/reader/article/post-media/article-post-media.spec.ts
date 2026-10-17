import { aLinkCard, anEntry } from '../../../../testing/entry-testing';
import { EntryDto } from '../../models';
import { articlePostMedia } from './article-post-media';

const photo = {
  url: 'https://files.example/a.jpg',
  kind: 'image' as const,
  width: 800,
  height: 600,
};
const clip = {
  url: 'https://video.example/v.mp4',
  kind: 'video' as const,
  previewImageUrl: 'https://video.example/v.jpg',
};
const poster = { url: 'https://video.example/v.jpg', kind: 'image' as const };

function post(overrides: Partial<EntryDto> = {}): EntryDto {
  return anEntry({ titleDerived: true, ...overrides });
}

function article(overrides: Partial<EntryDto> = {}): EntryDto {
  return anEntry({ titleDerived: false, ...overrides });
}

describe('articlePostMedia', () => {
  it('shows a post its own attachments', () => {
    expect(articlePostMedia(post({ media: [photo] }), '<p>Hi</p>')).toEqual([photo]);
  });

  it('shows an article none: its media lead the page as hero and body', () => {
    expect(articlePostMedia(article({ media: [photo] }), '<p>Hi</p>')).toEqual([]);
  });

  it('does not repeat a video poster as a picture', () => {
    expect(articlePostMedia(post({ media: [poster, clip] }), '')).toEqual([clip]);
  });

  it("leaves a link card's picture to the card", () => {
    const card = { ...aLinkCard(), imageUrl: photo.url };
    expect(articlePostMedia(post({ media: [photo], linkCards: [card] }), '')).toEqual([]);
  });

  it('skips what the body already shows, as a Bluesky post stored before #1656', () => {
    const html = `<figure class="post-images"><img src="${photo.url}"></figure>`;
    expect(articlePostMedia(post({ media: [photo] }), html)).toEqual([]);
  });

  it('matches a body source after entity decoding', () => {
    const gif = {
      url: 'https://static.example/a.gif?hh=280&ww=498',
      kind: 'image' as const,
      animated: true,
    };
    const html = '<img src="https://static.example/a.gif?hh&#61;280&amp;ww&#61;498">';
    expect(articlePostMedia(post({ media: [gif] }), html)).toEqual([]);
  });

  it('skips a video the body plays itself', () => {
    expect(articlePostMedia(post({ media: [clip] }), `<video src="${clip.url}"></video>`)).toEqual(
      [],
    );
  });

  it('skips a video whose source element the body plays', () => {
    const html = `<video><source src="${clip.url}"></video>`;
    expect(articlePostMedia(post({ media: [clip] }), html)).toEqual([]);
  });

  it('shows at most four, as the list does', () => {
    const photos = [1, 2, 3, 4, 5].map((index) => ({
      ...photo,
      url: `https://files.example/${index}.jpg`,
    }));
    expect(articlePostMedia(post({ media: photos }), '')).toEqual(photos.slice(0, 4));
  });
});
