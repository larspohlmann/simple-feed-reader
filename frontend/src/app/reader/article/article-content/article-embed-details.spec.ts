import { Observable, Subject, of, throwError } from 'rxjs';
import {
  VIDEO_ID,
  VIDEO_MEDIA,
  WATCH_URL,
  WIDGET_URL,
} from '../../../../testing/embed-media-testing';
import { EmbedMedia } from '../../audio/embed-media';
import { TrackDetails, chaptersOnly } from '../../audio/audio-track';
import { AudioPlayerService } from '../../audio-player.service';
import { EmbedDetailsApi } from '../../embed-details-api';
import { ArticleEmbedDetails } from './article-embed-details';

const API_FRAME_URL = `https://www.youtube-nocookie.com/embed/${VIDEO_ID}?enablejsapi=1`;
const VIDEO_DETAILS: TrackDetails = {
  title: 'A video',
  durationSeconds: 300,
  chapters: [
    { startSeconds: 0, title: 'Intro' },
    { startSeconds: 90, title: 'Demo' },
  ],
  artworkUrl: null,
};
const TRACK_DETAILS: TrackDetails = {
  title: 'A track',
  durationSeconds: null,
  chapters: [{ startSeconds: 30, title: 'Side B' }],
  artworkUrl: null,
};
const UNCHAPTERED: TrackDetails = {
  title: 'A plain video',
  durationSeconds: 120,
  chapters: [],
  artworkUrl: null,
};
const BODY_CHAPTERS = [
  { startSeconds: 0, title: 'Opening' },
  { startSeconds: 45, title: 'Body' },
];

function frameOf(source: string): HTMLIFrameElement {
  const frame = document.createElement('iframe');
  frame.setAttribute('src', source);
  return frame;
}

describe('ArticleEmbedDetails', () => {
  let detailsOf: jest.Mock<Observable<TrackDetails>, [EmbedMedia]>;
  let found: jest.Mock<void, [HTMLIFrameElement, TrackDetails]>;
  let describeTrack: jest.Mock<void, [string, TrackDetails]>;
  let details: ArticleEmbedDetails;

  function answering(answers: Record<string, Observable<TrackDetails>>): void {
    detailsOf.mockImplementation((media) => answers[media.url]);
  }

  beforeEach(() => {
    detailsOf = jest.fn();
    found = jest.fn();
    describeTrack = jest.fn();
    details = new ArticleEmbedDetails(
      { detailsOf } as unknown as EmbedDetailsApi,
      { describe: describeTrack } as unknown as AudioPlayerService,
    );
  });

  it('asks once per distinct embed and tells of it under every frame that shows it', () => {
    answering({ [WATCH_URL]: of(VIDEO_DETAILS) });
    const first = frameOf(API_FRAME_URL);
    const second = frameOf(API_FRAME_URL);

    details.load([first, second], found);

    expect(detailsOf).toHaveBeenCalledTimes(1);
    expect(detailsOf).toHaveBeenCalledWith(VIDEO_MEDIA);
    expect(found.mock.calls).toEqual([
      [first, VIDEO_DETAILS],
      [second, VIDEO_DETAILS],
    ]);
  });

  it('tells of an embed without chapters too, and keeps its answer', () => {
    answering({ [WATCH_URL]: of(UNCHAPTERED), [WIDGET_URL]: of(TRACK_DETAILS) });
    const video = frameOf(API_FRAME_URL);
    const widget = frameOf(WIDGET_URL);

    details.load([video, widget], found);

    expect(found.mock.calls).toEqual([
      [video, UNCHAPTERED],
      [widget, TRACK_DETAILS],
    ]);
    expect(details.detailsOf(WATCH_URL)).toEqual(UNCHAPTERED);
  });

  it('knows the details of both kinds by their embed url', () => {
    answering({ [WATCH_URL]: of(VIDEO_DETAILS), [WIDGET_URL]: of(TRACK_DETAILS) });

    details.load([frameOf(API_FRAME_URL), frameOf(WIDGET_URL)], found);

    expect(details.detailsOf(WATCH_URL)).toEqual(VIDEO_DETAILS);
    expect(details.detailsOf(WIDGET_URL)).toEqual(TRACK_DETAILS);
  });

  it('knows nothing before the answer arrives', () => {
    answering({ [WATCH_URL]: new Subject<TrackDetails>() });

    details.load([frameOf(API_FRAME_URL)], found);

    expect(details.detailsOf(WATCH_URL)).toBeNull();
  });

  it('tells nothing when the provider or the limiter refused', () => {
    answering({ [WATCH_URL]: throwError(() => new Error('503')) });

    details.load([frameOf(API_FRAME_URL)], found);

    expect(found).not.toHaveBeenCalled();
    expect(details.detailsOf(WATCH_URL)).toBeNull();
  });

  it('asks nothing for a frame that plays no embed', () => {
    details.load([frameOf('https://player.vimeo.com/video/1')], found);

    expect(detailsOf).not.toHaveBeenCalled();
  });

  it('hands a late answer to nobody once released, and forgets what it knew', () => {
    const late = new Subject<TrackDetails>();
    answering({ [WATCH_URL]: late, [WIDGET_URL]: of(TRACK_DETAILS) });
    details.load([frameOf(API_FRAME_URL), frameOf(WIDGET_URL)], found);
    found.mockClear();

    details.release();
    late.next(VIDEO_DETAILS);

    expect(found).not.toHaveBeenCalled();
    expect(details.detailsOf(WATCH_URL)).toBeNull();
    expect(details.detailsOf(WIDGET_URL)).toBeNull();
  });

  it('describes every queued copy of an embed once its answer arrives', () => {
    answering({ [WATCH_URL]: of(VIDEO_DETAILS), [WIDGET_URL]: of(TRACK_DETAILS) });

    details.load([frameOf(API_FRAME_URL), frameOf(API_FRAME_URL), frameOf(WIDGET_URL)], found);

    expect(describeTrack.mock.calls).toEqual([
      [WATCH_URL, VIDEO_DETAILS],
      [WIDGET_URL, TRACK_DETAILS],
    ]);
  });

  describe("seeded with the body's chapters", () => {
    beforeEach(() => details.seed(WATCH_URL, BODY_CHAPTERS));

    it('knows them, and describes the queued copy, before any answer', () => {
      expect(details.detailsOf(WATCH_URL)).toEqual(chaptersOnly(BODY_CHAPTERS));
      expect(describeTrack).toHaveBeenCalledWith(WATCH_URL, chaptersOnly(BODY_CHAPTERS));
    });

    it('keeps them and describes the queued copy when the answer fails', () => {
      answering({ [WATCH_URL]: throwError(() => new Error('503')) });

      details.load([frameOf(API_FRAME_URL)], found);

      expect(found).not.toHaveBeenCalled();
      expect(details.detailsOf(WATCH_URL)).toEqual(chaptersOnly(BODY_CHAPTERS));
      expect(describeTrack.mock.calls).toEqual([[WATCH_URL, chaptersOnly(BODY_CHAPTERS)]]);
    });

    it("takes the answer's title and length but keeps them over its chapters", () => {
      answering({ [WATCH_URL]: of(VIDEO_DETAILS), [WIDGET_URL]: of(TRACK_DETAILS) });
      const video = frameOf(API_FRAME_URL);
      const widget = frameOf(WIDGET_URL);
      const merged = { ...VIDEO_DETAILS, chapters: BODY_CHAPTERS };

      details.load([video, widget], found);

      expect(found.mock.calls).toEqual([
        [video, merged],
        [widget, TRACK_DETAILS],
      ]);
      expect(details.detailsOf(WATCH_URL)).toEqual(merged);
      expect(describeTrack).toHaveBeenLastCalledWith(WIDGET_URL, TRACK_DETAILS);
      expect(describeTrack).toHaveBeenCalledWith(WATCH_URL, merged);
    });

    it('forgets them once released', () => {
      details.release();
      answering({ [WATCH_URL]: of(VIDEO_DETAILS) });

      details.load([frameOf(API_FRAME_URL)], found);

      expect(details.detailsOf(WATCH_URL)).toEqual(VIDEO_DETAILS);
    });
  });
});
