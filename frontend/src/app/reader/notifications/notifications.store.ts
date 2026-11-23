import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, computed, inject } from '@angular/core';
import { EMPTY, Subscription, catchError } from 'rxjs';
import { accountSignal, onIdentityChange } from '../../core/auth/session-identity';
import { Problem, parseProblem } from '../../core/problem';
import { SocialNetworkName } from '../../core/social-network';
import { ToastService } from '../../shared/toast/toast.service';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { NotificationGroupDto, NotificationsPageDto } from './notification.models';
import { NotificationsApi } from './notifications-api';

@Injectable({ providedIn: 'root' })
export class NotificationsStore {
  private readonly api = inject(NotificationsApi);
  private readonly toast = inject(ToastService);
  private readonly subscriptions = inject(SubscriptionsStore);

  private readonly openNetwork = accountSignal<SocialNetworkName | null>(null);
  private readonly shownGroups = accountSignal<readonly NotificationGroupDto[]>([]);
  private readonly missingScope = accountSignal<readonly string[]>([]);
  private readonly reconnectNeeded = accountSignal<readonly string[]>([]);
  private readonly cursor = accountSignal<number | null>(null);
  private readonly isLoading = accountSignal(false);
  private readonly isLoadingMore = accountSignal(false);
  private readonly isMarking = accountSignal(false);
  private readonly failure = accountSignal<Problem | null>(null);

  readonly network = this.openNetwork.asReadonly();
  readonly groups = this.shownGroups.asReadonly();
  readonly scopeMissing = this.missingScope.asReadonly();
  readonly needsReconnect = this.reconnectNeeded.asReadonly();
  readonly nextCursor = this.cursor.asReadonly();
  readonly loading = this.isLoading.asReadonly();
  readonly loadingMore = this.isLoadingMore.asReadonly();
  readonly marking = this.isMarking.asReadonly();
  readonly error = this.failure.asReadonly();
  readonly hasUnseen = computed(() => this.shownGroups().some((group) => !group.seen));

  private listRequest: Subscription | null = null;
  private markRequest: Subscription | null = null;

  constructor() {
    onIdentityChange(() => {
      this.listRequest?.unsubscribe();
      this.cancelMarking();
    });
  }

  open(network: SocialNetworkName): void {
    if (this.openNetwork() !== network) this.clearList();
    this.cancelMarking();
    this.openNetwork.set(network);
    this.loadFirstPage(network);
  }

  reload(): void {
    const network = this.openNetwork();
    if (network !== null) this.loadFirstPage(network);
  }

  refresh(): void {
    const network = this.openNetwork();
    if (network === null) return;
    this.isLoading.set(true);
    this.failure.set(null);
    this.listRequest?.unsubscribe();
    this.listRequest = this.api
      .refresh(network)
      .pipe(
        catchError((error: HttpErrorResponse) => {
          this.toast.showProblem(parseProblem(error));
          return EMPTY;
        }),
      )
      .subscribe({ complete: () => this.reloadListAndCounts(network) });
  }

  loadMore(): void {
    const network = this.openNetwork();
    const cursor = this.cursor();
    if (network === null || cursor === null || this.isLoading() || this.isLoadingMore()) return;
    this.isLoadingMore.set(true);
    this.failure.set(null);
    this.listRequest = this.api.page(network, cursor).subscribe({
      next: (page) => {
        this.shownGroups.update((current) => [...current, ...page.groups]);
        this.adopt(page);
        this.isLoadingMore.set(false);
      },
      error: (error: HttpErrorResponse) => {
        this.failure.set(parseProblem(error));
        this.isLoadingMore.set(false);
      },
    });
  }

  markAllSeen(): void {
    const network = this.openNetwork();
    const newest = this.shownGroups()[0];
    if (network === null || newest === undefined || this.isMarking() || this.isLoading()) return;
    this.isMarking.set(true);
    const upTo = newest.id;
    this.markRequest = this.api.markSeen(network, upTo).subscribe({
      next: () => {
        if (this.openNetwork() !== network) return;
        this.shownGroups.update((current) =>
          current.map((group) => (group.id <= upTo ? { ...group, seen: true } : group)),
        );
        this.subscriptions.reloadCountsQuietly();
        this.isMarking.set(false);
      },
      error: (error: HttpErrorResponse) => {
        this.toast.showProblem(parseProblem(error));
        this.isMarking.set(false);
        this.reloadListAndCounts(network);
      },
    });
  }

  dismissError(): void {
    this.failure.set(null);
  }

  private cancelMarking(): void {
    this.markRequest?.unsubscribe();
    this.isMarking.set(false);
  }

  private clearList(): void {
    this.shownGroups.set([]);
    this.missingScope.set([]);
    this.reconnectNeeded.set([]);
  }

  private reloadListAndCounts(network: SocialNetworkName): void {
    if (this.openNetwork() !== network) return;
    this.subscriptions.reloadCountsQuietly();
    this.loadFirstPage(network);
  }

  private loadFirstPage(network: SocialNetworkName): void {
    this.cursor.set(null);
    this.isLoading.set(true);
    this.isLoadingMore.set(false);
    this.failure.set(null);
    this.listRequest?.unsubscribe();
    this.listRequest = this.api.page(network, null).subscribe({
      next: (page) => {
        this.shownGroups.set(page.groups);
        this.adopt(page);
        this.isLoading.set(false);
      },
      error: (error: HttpErrorResponse) => {
        this.clearList();
        this.failure.set(parseProblem(error));
        this.isLoading.set(false);
      },
    });
  }

  private adopt(page: NotificationsPageDto): void {
    this.cursor.set(page.nextCursor);
    this.missingScope.set(page.scopeMissing);
    this.reconnectNeeded.set(page.needsReconnect);
  }
}
