import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../core/api';
import { SOUND_MEDIA, VIDEO_ID, VIDEO_MEDIA } from '../../testing/embed-media-testing';
import type { TrackDetails } from './audio/audio-track';
import { EmbedDetailsApi } from './embed-details-api';

const PLAYLIST_WIDGET_URL =
  'https://w.soundcloud.com/player/?url=https%3A%2F%2Fapi.soundcloud.com%2Fplaylists%2F42';
const VIDEO_PATH = `https://api.test/api/youtube/videos/${VIDEO_ID}`;
const DETAILS: TrackDetails = {
  title: 'A mix',
  durationSeconds: 4964,
  chapters: [{ startSeconds: 0, title: 'Intro' }],
};

describe('EmbedDetailsApi', () => {
  let api: EmbedDetailsApi;
  let controller: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    api = TestBed.inject(EmbedDetailsApi);
    controller = TestBed.inject(HttpTestingController);
  });

  afterEach(() => controller.verify());

  it('GETs the details of an embedded YouTube video', () => {
    let received: unknown;
    api.detailsOf(VIDEO_MEDIA).subscribe((answer) => (received = answer));

    const request = controller.expectOne(VIDEO_PATH);
    expect(request.request.method).toBe('GET');
    request.flush(DETAILS);

    expect(received).toEqual(DETAILS);
  });

  it('GETs the details of an embedded SoundCloud track', () => {
    api.detailsOf(SOUND_MEDIA).subscribe();

    const request = controller.expectOne('https://api.test/api/soundcloud/tracks/123456789');
    expect(request.request.method).toBe('GET');
    request.flush({ title: 'A track', durationSeconds: null, chapters: [] });
  });

  it('asks nothing for an embed without a track id, and completes', () => {
    const completed = jest.fn();

    api
      .detailsOf({ kind: 'soundcloud', url: PLAYLIST_WIDGET_URL, posterUrl: null })
      .subscribe({ complete: completed });

    controller.expectNone(() => true);
    expect(completed).toHaveBeenCalled();
  });

  it('asks once per embed and answers a second caller from the first answer', () => {
    const received: unknown[] = [];
    api.detailsOf(VIDEO_MEDIA).subscribe((answer) => received.push(answer));
    controller.expectOne(VIDEO_PATH).flush(DETAILS);

    api.detailsOf(VIDEO_MEDIA).subscribe((answer) => received.push(answer));

    controller.expectNone(VIDEO_PATH);
    expect(received).toEqual([DETAILS, DETAILS]);
  });

  it('asks again after a failed answer', () => {
    const failed = jest.fn();
    api.detailsOf(VIDEO_MEDIA).subscribe({ error: failed });
    controller
      .expectOne(VIDEO_PATH)
      .flush(null, { status: 503, statusText: 'Service Unavailable' });

    let received: unknown;
    api.detailsOf(VIDEO_MEDIA).subscribe((answer) => (received = answer));
    controller.expectOne(VIDEO_PATH).flush(DETAILS);

    expect(failed).toHaveBeenCalled();
    expect(received).toEqual(DETAILS);
  });
});
