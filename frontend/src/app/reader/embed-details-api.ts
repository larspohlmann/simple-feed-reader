import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { EMPTY, Observable, catchError, shareReplay, throwError } from 'rxjs';
import { API_BASE_URL } from '../core/api';
import { EmbedMedia, soundCloudTrackId, youTubeVideoId } from './audio/embed-media';
import type { TrackDetails } from './audio/audio-track';

/** The own title, length and chapters of embedded YouTube videos and SoundCloud tracks (#1824),
 *  asked once per embed for the session; a failed answer is forgotten so a later render asks again. */
@Injectable({ providedIn: 'root' })
export class EmbedDetailsApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);
  private readonly byEmbedUrl = new Map<string, Observable<TrackDetails>>();

  /** Nothing for an embed without a video or track id, so callers need no null check. */
  detailsOf(media: EmbedMedia): Observable<TrackDetails> {
    const path = detailsPath(media);
    if (path === null) return EMPTY;
    const known = this.byEmbedUrl.get(media.url);
    if (known) return known;
    const details = this.http.get<TrackDetails>(`${this.base}${path}`).pipe(
      catchError((error: unknown) => {
        this.byEmbedUrl.delete(media.url);
        return throwError(() => error);
      }),
      shareReplay({ bufferSize: 1, refCount: false }),
    );
    this.byEmbedUrl.set(media.url, details);
    return details;
  }
}

function detailsPath(media: EmbedMedia): string | null {
  if (media.kind === 'youtube') {
    const videoId = youTubeVideoId(media.url);
    return videoId === null ? null : `/api/youtube/videos/${videoId}`;
  }
  const trackId = soundCloudTrackId(media.url);
  return trackId === null ? null : `/api/soundcloud/tracks/${trackId}`;
}
