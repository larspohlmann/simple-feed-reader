import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Observable, finalize, tap } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import { parseProblem, problemMessage } from '../../core/problem';
import { SocialNetworkName } from '../../core/social-network';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';

export type SocialAccountStatus = 'connected' | 'needs_reconnect';

export interface SocialAccount {
  readonly id: number;
  readonly network: SocialNetworkName;
  readonly handle: string;
  readonly serviceUrl: string;
  readonly status: SocialAccountStatus;
  readonly isDefault: boolean;
  readonly credentialHint: string;
  readonly connectedAt: string;
}

interface SocialAccountList {
  readonly accounts: SocialAccount[];
}

@Injectable()
export class SocialAccountsService {
  private readonly http = inject(HttpClient);
  private readonly endpoint = `${inject(API_BASE_URL)}/api/me/social-accounts`;
  private readonly subscriptions = inject(SubscriptionsStore);

  readonly accounts = signal<readonly SocialAccount[] | null>(null);
  readonly failure = signal<string | null>(null);
  readonly busy = signal(false);

  load(): void {
    this.http.get<SocialAccountList>(this.endpoint).subscribe({
      next: (list) => this.accounts.set(list.accounts),
      error: (error: HttpErrorResponse) => this.fail(error),
    });
  }

  connectBluesky(handle: string, appPassword: string): Observable<SocialAccount> {
    return this.upserting(
      this.http.post<SocialAccount>(`${this.endpoint}/bluesky`, { handle, appPassword }),
    );
  }

  connectMastodon(instanceUrl: string, accessToken: string): Observable<SocialAccount> {
    return this.upserting(
      this.http.post<SocialAccount>(`${this.endpoint}/mastodon`, { instanceUrl, accessToken }),
    );
  }

  reconnect(id: number, secret: string): Observable<SocialAccount> {
    return this.upserting(
      this.http.put<SocialAccount>(`${this.endpoint}/${id}/credential`, { secret }),
    );
  }

  makeDefault(id: number): void {
    this.replacingList(this.http.put<SocialAccountList>(`${this.endpoint}/${id}/default`, {}));
  }

  /** Disconnecting removes the account's private feeds, so the reader reloads once the account is gone. */
  disconnect(id: number): void {
    this.replacingList(
      this.http
        .delete<SocialAccountList>(`${this.endpoint}/${id}`)
        .pipe(tap(() => this.subscriptions.load())),
    );
  }

  /** A connect creates the Home feed and a reconnect resumes the private feeds: the reader reloads either way. */
  private upserting(request: Observable<SocialAccount>): Observable<SocialAccount> {
    this.begin();
    return request.pipe(
      tap({
        next: (account) => {
          this.accounts.update((current) => this.withAccount(current ?? [], account));
          this.subscriptions.load();
        },
        error: (error: HttpErrorResponse) => this.fail(error),
      }),
      finalize(() => this.busy.set(false)),
    );
  }

  private withAccount(
    current: readonly SocialAccount[],
    account: SocialAccount,
  ): readonly SocialAccount[] {
    if (!current.some((candidate) => candidate.id === account.id)) return [...current, account];

    return current.map((candidate) => (candidate.id === account.id ? account : candidate));
  }

  private replacingList(request: Observable<SocialAccountList>): void {
    this.begin();
    request.pipe(finalize(() => this.busy.set(false))).subscribe({
      next: (list) => this.accounts.set(list.accounts),
      error: (error: HttpErrorResponse) => this.fail(error),
    });
  }

  private begin(): void {
    this.busy.set(true);
    this.failure.set(null);
  }

  private fail(error: HttpErrorResponse): void {
    this.failure.set(problemMessage(parseProblem(error)));
  }
}
