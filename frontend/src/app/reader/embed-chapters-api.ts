import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { EMPTY, Observable } from 'rxjs';
import { API_BASE_URL } from '../core/api';
import { EmbedMedia, soundCloudTrackId, youTubeVideoId } from './audio/embed-media';
import { ChapterDto } from './models';

export interface EmbedChaptersDto {
  chapters: ChapterDto[];
}

/** The chapters of embedded YouTube videos and SoundCloud tracks (#1824). */
@Injectable({ providedIn: 'root' })
export class EmbedChaptersApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  /** Nothing for an embed without a video or track id, so callers need no null check. */
  chaptersOf(media: EmbedMedia): Observable<EmbedChaptersDto> {
    const path = chaptersPath(media);
    return path === null ? EMPTY : this.http.get<EmbedChaptersDto>(`${this.base}${path}`);
  }
}

function chaptersPath(media: EmbedMedia): string | null {
  if (media.kind === 'youtube') {
    const videoId = youTubeVideoId(media.url);
    return videoId === null ? null : `/api/youtube/videos/${videoId}/chapters`;
  }
  const trackId = soundCloudTrackId(media.url);
  return trackId === null ? null : `/api/soundcloud/tracks/${trackId}/chapters`;
}
