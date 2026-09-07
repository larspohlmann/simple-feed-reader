import { firstAudioAttachment, toAudioTrack } from './audio-attachment';
import { EntryAttachmentDto, EntryDto } from './models';

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 'The entry headline',
  url: null,
  author: null,
  summary: null,
  contentHtml: null,
  imageUrl: 'https://x.test/cover.jpg',
  imageWidth: null,
  imageHeight: null,
  media: [],
  attachments: [],
  publishedAt: null,
  createdAt: 'x',
  subscriptionId: 7,
  source: 'Src',
  faviconUrl: 'https://x.test/favicon.ico',
  isHidden: false,
  isFavorite: false,
  isKept: false,
  isViewed: false,
  ...over,
});

describe('firstAudioAttachment', () => {
  it('trusts an audio mime type', () => {
    const audio: EntryAttachmentDto = { url: 'https://x.test/ep.bin', mimeType: 'audio/mpeg' };

    expect(firstAudioAttachment([audio])).toBe(audio);
  });

  it('rejects a declared non-audio mime type even with an audio-looking url', () => {
    const video: EntryAttachmentDto = { url: 'https://x.test/ep.mp3', mimeType: 'video/mp4' };

    expect(firstAudioAttachment([video])).toBeNull();
  });

  it('sniffs the extension when the feed omits the mime type', () => {
    const audio: EntryAttachmentDto = { url: 'https://x.test/ep.m4a?token=abc' };

    expect(firstAudioAttachment([audio])).toBe(audio);
  });

  it('ignores a non-audio extension when no mime type is given', () => {
    const pdf: EntryAttachmentDto = { url: 'https://x.test/notes.pdf' };

    expect(firstAudioAttachment([pdf])).toBeNull();
  });

  it('returns the first audio attachment, skipping a leading non-audio one', () => {
    const attachments: EntryAttachmentDto[] = [
      { url: 'https://x.test/clip.mp4', mimeType: 'video/mp4' },
      { url: 'https://x.test/ep.mp3', mimeType: 'audio/mpeg' },
    ];

    expect(firstAudioAttachment(attachments)).toBe(attachments[1]);
  });

  it('returns null for an empty list', () => {
    expect(firstAudioAttachment([])).toBeNull();
  });
});

describe('toAudioTrack', () => {
  it('carries the attachment url and duration and the entry artwork', () => {
    const attachment: EntryAttachmentDto = {
      url: 'https://x.test/ep.mp3',
      title: 'Episode 7',
      durationInSeconds: 1830,
    };

    const track = toAudioTrack(entry(), attachment);

    expect(track).toEqual({
      url: 'https://x.test/ep.mp3',
      title: 'Episode 7',
      faviconUrl: 'https://x.test/favicon.ico',
      imageUrl: 'https://x.test/cover.jpg',
      durationInSeconds: 1830,
    });
  });

  it('falls back to the entry title when the attachment has none', () => {
    const attachment: EntryAttachmentDto = { url: 'https://x.test/ep.mp3' };

    expect(toAudioTrack(entry(), attachment).title).toBe('The entry headline');
    expect(toAudioTrack(entry(), attachment).durationInSeconds).toBeNull();
  });
});
