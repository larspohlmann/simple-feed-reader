import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import { LabelPill, LabelTalliesResponse } from './labels.models';

/** The reader's labels and which entries carry them. */
@Injectable({ providedIn: 'root' })
export class LabelsApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  tallies(): Observable<LabelTalliesResponse> {
    return this.http.get<LabelTalliesResponse>(`${this.base}/api/labels`);
  }

  create(name: string): Observable<LabelPill> {
    return this.http
      .post<{ label: LabelPill }>(`${this.base}/api/labels`, { name })
      .pipe(map((response) => response.label));
  }

  rename(id: number, name: string): Observable<LabelPill> {
    return this.http
      .patch<{ label: LabelPill }>(`${this.base}/api/labels/${id}`, { name })
      .pipe(map((response) => response.label));
  }

  remove(id: number): Observable<void> {
    return this.http.delete<void>(`${this.base}/api/labels/${id}`);
  }

  attach(entryId: number, labelId: number): Observable<void> {
    return this.http.put<void>(this.entryLabelUrl(entryId, labelId), null);
  }

  detach(entryId: number, labelId: number): Observable<void> {
    return this.http.delete<void>(this.entryLabelUrl(entryId, labelId));
  }

  private entryLabelUrl(entryId: number, labelId: number): string {
    return `${this.base}/api/entries/${entryId}/labels/${labelId}`;
  }
}
