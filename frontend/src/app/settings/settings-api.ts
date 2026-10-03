import { HttpClient, HttpResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../core/api';
import {
  DebugLogDetail,
  DebugLogEntry,
  DebugLogPayload,
  OpmlImportResult,
  ReadingActivity,
  RestorePreview,
  RestoreResult,
  RunHistoryMonthPage,
  RunHistoryOverview,
} from './settings.models';

@Injectable({ providedIn: 'root' })
export class SettingsApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  exportOpml(): Observable<string> {
    return this.http.get(`${this.base}/api/opml/export`, { responseType: 'text' });
  }

  importOpml(xml: string): Observable<OpmlImportResult> {
    return this.http.post<OpmlImportResult>(`${this.base}/api/opml/import`, xml, {
      headers: { 'Content-Type': 'text/xml' },
    });
  }

  downloadAccountBackup(): Observable<HttpResponse<Blob>> {
    return this.http.get(`${this.base}/api/account/backup`, {
      responseType: 'blob',
      observe: 'response',
    });
  }

  previewAccountRestore(backup: Blob): Observable<RestorePreview> {
    return this.http.post<RestorePreview>(`${this.base}/api/account/restore/preview`, backup, {
      headers: { 'Content-Type': 'application/gzip' },
    });
  }

  startAccountRestore(foundation: Blob): Observable<RestoreResult> {
    return this.http.post<RestoreResult>(
      `${this.base}/api/account/restore/start?confirm=REPLACE`,
      foundation,
      { headers: { 'Content-Type': 'application/gzip' } },
    );
  }

  restoreEntryPart(part: Blob): Observable<RestoreResult> {
    return this.http.post<RestoreResult>(`${this.base}/api/account/restore/entries`, part, {
      headers: { 'Content-Type': 'application/gzip' },
    });
  }

  /** The provider calls logged for one for-you run, in call order, plus that
   *  run's own summary and the retained runs the panel may switch to. Without
   *  a runId the newest run answers -- null when the user has never run. */
  debugLog(runId?: number): Observable<DebugLogPayload> {
    const query = runId === undefined ? '' : `?run=${runId}`;
    return this.http.get<DebugLogPayload>(
      `${this.base}/api/recommendations/runs/debug-log${query}`,
    );
  }

  /** The newest profile run's calls, for the profile section's debug panel. */
  profileDebugLog(): Observable<{ entries: DebugLogEntry[] }> {
    return this.http.get<{ entries: DebugLogEntry[] }>(
      `${this.base}/api/me/ai/profile/runs/current/log`,
    );
  }

  /** The full request/response body for one logged provider call. */
  debugLogEntry(id: number): Observable<DebugLogDetail> {
    return this.http.get<DebugLogDetail>(`${this.base}/api/recommendations/runs/debug-log/${id}`);
  }

  /** How many articles the account opened on each of the last thirty days,
   *  bucketed in `timeZone` (IANA; the server falls back to UTC on an
   *  identifier its tzdata does not know) for the About page's reading chart. */
  readingActivity(timeZone: string): Observable<ReadingActivity> {
    return this.http.get<ReadingActivity>(`${this.base}/api/reading/activity`, {
      params: { tz: timeZone },
    });
  }

  /** Every month this account has run in, with that month's own run count and
   *  spend, plus the newest month's first page and the all-time total -- one
   *  call, since the card's first paint needs it all and each request costs a
   *  PHP boot. `timeZone` is IANA; the server buckets months in it, UTC on fallback. */
  runHistory(timeZone: string): Observable<RunHistoryOverview> {
    return this.http.get<RunHistoryOverview>(`${this.base}/api/recommendations/runs/history`, {
      params: { tz: timeZone },
    });
  }

  /** One month's runs, newest first. Without `before` this is the month's
   *  first page; with it, the next page after that cursor. */
  runHistoryMonth(
    month: string,
    timeZone: string,
    before?: number,
  ): Observable<RunHistoryMonthPage> {
    const params: Record<string, string | number> = { tz: timeZone };
    if (before !== undefined) params['before'] = before;
    return this.http.get<RunHistoryMonthPage>(
      `${this.base}/api/recommendations/runs/history/${month}`,
      { params },
    );
  }
}
