import { Observable, Subject, of, throwError } from 'rxjs';
import {
  SOUND_MEDIA,
  VIDEO_ID,
  VIDEO_MEDIA,
  WATCH_URL,
  WIDGET_URL,
} from '../../../../testing/embed-media-testing';
import { EmbedMedia } from '../../audio/embed-media';
import { ChapterDto } from '../../models';
import { EmbedChaptersApi, EmbedChaptersDto } from '../../embed-chapters-api';
import { ArticleEmbedChapters } from './article-embed-chapters';

const API_FRAME_URL = `https://www.youtube-nocookie.com/embed/${VIDEO_ID}?enablejsapi=1`;
const VIDEO_CHAPTERS: ChapterDto[] = [
  { startSeconds: 0, title: 'Intro' },
  { startSeconds: 90, title: 'Demo' },
];
const TRACK_CHAPTERS: ChapterDto[] = [{ startSeconds: 30, title: 'Side B' }];

function frameOf(source: string): HTMLIFrameElement {
  const frame = document.createElement('iframe');
  frame.setAttribute('src', source);
  return frame;
}

describe('ArticleEmbedChapters', () => {
  let embedChapters: jest.Mock<Observable<EmbedChaptersDto>, [EmbedMedia]>;
  let found: jest.Mock<void, [HTMLIFrameElement, EmbedMedia, ChapterDto[]]>;
  let chapters: ArticleEmbedChapters;

  function answering(answers: Record<string, Observable<EmbedChaptersDto>>): void {
    embedChapters.mockImplementation((media) => answers[media.url]);
  }

  beforeEach(() => {
    embedChapters = jest.fn();
    found = jest.fn();
    chapters = new ArticleEmbedChapters({
      chaptersOf: embedChapters,
    } as unknown as EmbedChaptersApi);
  });

  it('asks once per distinct embed and lists it under every frame that shows it', () => {
    answering({ [WATCH_URL]: of({ chapters: VIDEO_CHAPTERS }) });
    const first = frameOf(API_FRAME_URL);
    const second = frameOf(API_FRAME_URL);

    chapters.load([first, second], found);

    expect(embedChapters).toHaveBeenCalledTimes(1);
    expect(embedChapters).toHaveBeenCalledWith(VIDEO_MEDIA);
    expect(found.mock.calls).toEqual([
      [first, VIDEO_MEDIA, VIDEO_CHAPTERS],
      [second, VIDEO_MEDIA, VIDEO_CHAPTERS],
    ]);
  });

  it('tells only of the embeds that have chapters', () => {
    answering({
      [WATCH_URL]: of({ chapters: [] }),
      [WIDGET_URL]: of({ chapters: TRACK_CHAPTERS }),
    });
    const widget = frameOf(WIDGET_URL);

    chapters.load([frameOf(API_FRAME_URL), widget], found);

    expect(found.mock.calls).toEqual([[widget, SOUND_MEDIA, TRACK_CHAPTERS]]);
  });

  it('knows the chapters of both kinds by their embed url', () => {
    answering({
      [WATCH_URL]: of({ chapters: VIDEO_CHAPTERS }),
      [WIDGET_URL]: of({ chapters: TRACK_CHAPTERS }),
    });

    chapters.load([frameOf(API_FRAME_URL), frameOf(WIDGET_URL)], found);

    expect(chapters.chaptersOf(WATCH_URL)).toEqual(VIDEO_CHAPTERS);
    expect(chapters.chaptersOf(WIDGET_URL)).toEqual(TRACK_CHAPTERS);
  });

  it('knows no chapters before the answer arrives', () => {
    answering({ [WATCH_URL]: new Subject<EmbedChaptersDto>() });

    chapters.load([frameOf(API_FRAME_URL)], found);

    expect(chapters.chaptersOf(WATCH_URL)).toEqual([]);
  });

  it('shows nothing when the provider or the limiter refused', () => {
    answering({ [WATCH_URL]: throwError(() => new Error('503')) });

    chapters.load([frameOf(API_FRAME_URL)], found);

    expect(found).not.toHaveBeenCalled();
    expect(chapters.chaptersOf(WATCH_URL)).toEqual([]);
  });

  it('asks nothing for a frame that plays no embed', () => {
    chapters.load([frameOf('https://player.vimeo.com/video/1')], found);

    expect(embedChapters).not.toHaveBeenCalled();
  });

  it('hands a late answer to nobody once released, and forgets what it knew', () => {
    const late = new Subject<EmbedChaptersDto>();
    answering({ [WATCH_URL]: late, [WIDGET_URL]: of({ chapters: TRACK_CHAPTERS }) });
    chapters.load([frameOf(API_FRAME_URL), frameOf(WIDGET_URL)], found);
    found.mockClear();

    chapters.release();
    late.next({ chapters: VIDEO_CHAPTERS });

    expect(found).not.toHaveBeenCalled();
    expect(chapters.chaptersOf(WATCH_URL)).toEqual([]);
    expect(chapters.chaptersOf(WIDGET_URL)).toEqual([]);
  });
});
