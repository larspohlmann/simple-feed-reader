import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../core/api';
import { SOUND_MEDIA, VIDEO_ID, VIDEO_MEDIA } from '../../testing/embed-media-testing';
import { EmbedChaptersApi } from './embed-chapters-api';

const PLAYLIST_WIDGET_URL =
  'https://w.soundcloud.com/player/?url=https%3A%2F%2Fapi.soundcloud.com%2Fplaylists%2F42';

describe('EmbedChaptersApi', () => {
  let api: EmbedChaptersApi;
  let controller: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    api = TestBed.inject(EmbedChaptersApi);
    controller = TestBed.inject(HttpTestingController);
  });

  afterEach(() => controller.verify());

  it('GETs the chapters of an embedded YouTube video', () => {
    let received: unknown;
    api.chaptersOf(VIDEO_MEDIA).subscribe((answer) => (received = answer));

    const request = controller.expectOne(
      `https://api.test/api/youtube/videos/${VIDEO_ID}/chapters`,
    );
    expect(request.request.method).toBe('GET');
    request.flush({ chapters: [{ startSeconds: 0, title: 'Intro' }] });

    expect(received).toEqual({ chapters: [{ startSeconds: 0, title: 'Intro' }] });
  });

  it('GETs the chapters of an embedded SoundCloud track', () => {
    api.chaptersOf(SOUND_MEDIA).subscribe();

    const request = controller.expectOne(
      'https://api.test/api/soundcloud/tracks/123456789/chapters',
    );
    expect(request.request.method).toBe('GET');
    request.flush({ chapters: [] });
  });

  it('asks nothing for an embed without a track id, and completes', () => {
    const completed = jest.fn();

    api
      .chaptersOf({ kind: 'soundcloud', url: PLAYLIST_WIDGET_URL, posterUrl: null })
      .subscribe({ complete: completed });

    controller.expectNone(() => true);
    expect(completed).toHaveBeenCalled();
  });
});
