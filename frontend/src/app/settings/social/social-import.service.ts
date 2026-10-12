import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Observable, finalize, tap } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import { parseProblem, problemMessage } from '../../core/problem';

export type ImportCandidateState = 'new' | 'subscribed' | 'no_longer_followed';

export interface FollowCandidate {
  readonly identity: string;
  readonly handle: string;
  readonly displayName: string | null;
  readonly feedUrl: string;
  readonly state: ImportCandidateState;
  readonly subscriptionId: number | null;
}

export type CollectionKind = 'home' | 'list' | 'custom_feed';
export type CollectionState = 'new' | 'subscribed';

export interface CollectionCandidate {
  readonly kind: CollectionKind;
  readonly id: string | null;
  readonly name: string;
  readonly state: CollectionState;
  readonly subscriptionId: number | null;
}

export interface SocialImportCollection {
  readonly kind: CollectionKind;
  readonly id: string | null;
  readonly name: string;
}

export interface ImportCandidates {
  readonly follows: readonly FollowCandidate[];
  readonly lists: readonly CollectionCandidate[];
  readonly customFeeds: readonly CollectionCandidate[];
  readonly truncated: boolean;
}

export interface SocialImportFeed {
  readonly feedUrl: string;
  readonly title: string | null;
}

export interface SocialImportSelection {
  readonly subscribe: readonly SocialImportFeed[];
  readonly unsubscribe: readonly number[];
  readonly collections: readonly SocialImportCollection[];
}

export interface SocialImportResult {
  readonly imported: number;
  readonly alreadySubscribed: number;
  readonly invalid: number;
  readonly skippedOverLimit: number;
  readonly removed: number;
}

@Injectable()
export class SocialImportService {
  private readonly http = inject(HttpClient);
  private readonly endpoint = `${inject(API_BASE_URL)}/api/me/social-accounts`;

  readonly candidates = signal<ImportCandidates | null>(null);
  readonly loading = signal(false);
  readonly importing = signal(false);
  readonly failure = signal<string | null>(null);

  load(accountId: number): void {
    this.loading.set(true);
    this.failure.set(null);
    this.http
      .get<ImportCandidates>(`${this.endpoint}/${accountId}/import-candidates`)
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (candidates) => this.candidates.set(candidates),
        error: (error: HttpErrorResponse) => this.fail(error),
      });
  }

  importSelection(
    accountId: number,
    selection: SocialImportSelection,
  ): Observable<SocialImportResult> {
    this.importing.set(true);
    this.failure.set(null);
    return this.http
      .post<SocialImportResult>(`${this.endpoint}/${accountId}/import`, selection)
      .pipe(
        tap({ error: (error: HttpErrorResponse) => this.fail(error) }),
        finalize(() => this.importing.set(false)),
      );
  }

  private fail(error: HttpErrorResponse): void {
    this.failure.set(problemMessage(parseProblem(error)));
  }
}
