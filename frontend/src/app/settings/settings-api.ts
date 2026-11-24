import { HttpClient, HttpResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../core/api';
import { OpmlImportRequest, OpmlPreview } from './import/opml-import.models';
import {
  DebugLogDetail,
  DebugLogEntry,
  AiRunKind,
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

  previewOpml(opml: string, parentTagName: string | null): Observable<OpmlPreview> {
    return this.http.post<OpmlPreview>(`${this.base}/api/opml/preview`, { opml, parentTagName });
  }

  importOpml(request: OpmlImportRequest): Observable<OpmlImportResult> {
    return this.http.post<OpmlImportResult>(`${this.base}/api/opml/import`, request);
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

  /** The provider calls logged for one run, in call order, plus that run's
   *  own summary and the retained runs of every kind the panel may switch to.
   *  Without a run the server's default answers -- null when nothing ran. */
  debugLog(run?: { kind: AiRunKind; id: number }): Observable<DebugLogPayload> {
    const query = run === undefined ? '' : `?kind=${run.kind}&run=${run.id}`;
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

  /** The last thirty UTC days of new and read articles for the About page's charts. */
  readingActivity(): Observable<ReadingActivity> {
    return this.http.get<ReadingActivity>(`${this.base}/api/reading/activity`);
  }

  /** Every month this account has run in, with that month's own run count and
   *  spend, plus the newest month's first page and the all-time total -- one
   *  call, since the card's first paint needs it all and each request costs a
   *  PHP boot. `timeZone` is IANA; the server buckets months in it, UTC on fallback. */
  runHistory(timeZone: string, kind?: AiRunKind): Observable<RunHistoryOverview> {
    return this.http.get<RunHistoryOverview>(`${this.base}/api/recommendations/runs/history`, {
      params: this.historyParams(timeZone, kind),
    });
  }

  /** One month's runs, newest first. Without `before` this is the month's
   *  first page; with it, the next page after that opaque cursor. */
  runHistoryMonth(
    month: string,
    timeZone: string,
    page: { kind?: AiRunKind; before?: string } = {},
  ): Observable<RunHistoryMonthPage> {
    const params = this.historyParams(timeZone, page.kind);
    if (page.before !== undefined) params['before'] = page.before;
    return this.http.get<RunHistoryMonthPage>(
      `${this.base}/api/recommendations/runs/history/${month}`,
      { params },
    );
  }

  private historyParams(timeZone: string, kind: AiRunKind | undefined): Record<string, string> {
    const params: Record<string, string> = { tz: timeZone };
    if (kind !== undefined) params['kind'] = kind;
    return params;
  }
}
