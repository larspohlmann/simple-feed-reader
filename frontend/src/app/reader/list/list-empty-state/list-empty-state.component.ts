import { Component, computed, inject, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { CaughtUpIllustrationComponent } from '../caught-up-illustration/caught-up-illustration.component';
import { CatalogStore } from '../../feeds/catalog/catalog.store';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { Selection, visibleSearchTerm } from '../../query/query';

const FEW_SUBSCRIPTIONS = 5;

/** The message a list shows when nothing in it is left to see. */
@Component({
  selector: 'app-list-empty-state',
  imports: [RouterLink, TranslocoPipe, CaughtUpIllustrationComponent],
  templateUrl: './list-empty-state.component.html',
  styleUrl: './list-empty-state.component.scss',
})
export class ListEmptyStateComponent {
  readonly selection = input.required<Selection>();
  /** How many saved searches the account keeps. The combined view's empty state
   *  distinguishes "you have none" from "yours match nothing". */
  readonly savedSearchCount = input(0);

  private readonly catalog = inject(CatalogStore);
  private readonly subscriptions = inject(SubscriptionsStore);

  /** The search term for the message — the trailing space is the server's
   *  whole-word-match signal, not part of what the user typed, so it must not
   *  appear in text a human reads (#408 follow-up). */
  protected readonly displayedSearchTerm = computed(() =>
    visibleSearchTerm(this.selection().term ?? ''),
  );

  /** True only once the catalog has been resolved AND has no entries.
   *  Unresolved reads as not-empty, so the /discover link is never hidden on a
   *  guess — it simply shows until the shell (which loads the catalog on the
   *  onboarding path) proves the catalog empty. */
  private readonly catalogEmpty = computed(
    () => this.catalog.resolved() && !this.catalog.hasEntries(),
  );

  /** The /discover nudge is for an account still building its reading list. */
  protected readonly suggestFeeds = computed(
    () =>
      !this.catalogEmpty() &&
      this.subscriptions.resolved() &&
      this.subscriptions.subscriptions().length < FEW_SUBSCRIPTIONS,
  );
}
