import { Injectable, computed, inject } from '@angular/core';
import { Router } from '@angular/router';
import { TranslocoService } from '@jsverse/transloco';
import { ReaderRouteState } from './reader-route-state.service';
import { ListHeading } from './list-heading.service';
import { SavedSearchesStore } from '../state/saved-searches.store';
import { isDirectSearch, isPhraseTerm, isWholeWordTerm, visibleSearchTerm } from '../query/query';
import { SavedSearchDto } from '../models';
import { ConfirmData } from '../../shared/confirm-dialog/confirm-dialog.component';
import { ConfirmService } from '../../shared/confirm-dialog/confirm.service';
import { CONFIRMATION_DURATION_MS, ToastService } from '../../shared/toast/toast.service';

@Injectable()
export class SavedSearchToggle {
  private readonly router = inject(Router);
  private readonly confirm = inject(ConfirmService);
  private readonly toast = inject(ToastService);
  private readonly i18n = inject(TranslocoService);
  private readonly savedSearchesStore = inject(SavedSearchesStore);
  private readonly heading = inject(ListHeading);
  private readonly selection = inject(ReaderRouteState).selection;

  readonly viewing = computed(() => this.selection().kind === 'saved-search');
  /** Whether the header offers its Save/Remove control: a direct search can be
   *  saved, a saved search removed. Named so a third search-like kind can't slip
   *  the gate the way #1118 dropped this one. */
  readonly canToggle = computed(() => isDirectSearch(this.selection()) || this.viewing());

  /** The current search decoded into the pair a saved search stores: the
   *  visible term and the whole-word flag. Null outside a search — the one
   *  place that reads the trailing-space signal, so downstream never re-decodes it (#408). */
  private readonly searchedTermAndMode = computed(() => {
    const selection = this.selection();
    if (selection.kind !== 'search') return null;
    const raw = selection.term ?? '';

    // A phrase (wrapping quotes) overrides whole-word (a trailing space) when a
    // query carries both, exactly as the server decides it (#702), so the
    // whole-word flag is read only when the query is not a phrase.
    const phrase = isPhraseTerm(raw);

    return { term: visibleSearchTerm(raw), wholeWord: !phrase && isWholeWordTerm(raw), phrase };
  });

  /** The saved search matching the current selection, or null. A search's
   *  identity is its visible term plus its mode — the whole-word and phrase
   *  flags — so all three must match. */
  readonly current = computed(() => {
    if (this.selection().kind === 'saved-search') return this.heading.activeSavedSearch();
    const current = this.searchedTermAndMode();
    if (current === null) return null;

    return (
      this.savedSearchesStore
        .savedSearches()
        .find(
          (saved) =>
            saved.term === current.term &&
            saved.wholeWord === current.wholeWord &&
            saved.phrase === current.phrase,
        ) ?? null
    );
  });

  readonly actionLabel = computed(() =>
    this.current() ? 'reader.removeSavedSearch' : 'reader.saveSearch',
  );

  /** Save the search being looked at, or drop it when already saved -- one
   *  command, because the header offers one button whose label/icon flip on
   *  this state. Saving toasts on real HTTP success; removing confirms first (#581). */
  toggle(): void {
    const saved = this.current();
    if (saved) {
      this.confirmRemoveSavedSearch(saved.id);

      return;
    }

    const current = this.searchedTermAndMode();
    if (!current) return;
    this.savedSearchesStore.createSavedSearch(current, () =>
      this.toast.show({
        message: this.i18n.translate('reader.searchSaved'),
        durationMs: CONFIRMATION_DURATION_MS,
      }),
    );
  }

  private confirmRemoveSavedSearch(id: number): void {
    const data: ConfirmData = {
      title: this.i18n.translate('reader.removeSavedSearchConfirm'),
      message: this.i18n.translate('reader.removeSavedSearchConfirmMessage'),
      confirmLabel: this.i18n.translate('reader.removeSavedSearch'),
    };
    this.confirm.confirmThen(data, () => {
      // Removing the search you are viewing by its slug path leaves that path
      // pointing at nothing, so fall back to the combined list; an unsaved
      // `?q=` search stays put and simply flips its button back to Save.
      const returnToCombined = this.viewing()
        ? () => void this.router.navigate(['/searches/saved/all'])
        : undefined;
      this.savedSearchesStore.removeSavedSearch(id, returnToCombined);
    });
  }

  /** The sidebar's per-search mail icon: confirm before flipping
   *  `includeInDigest`, with different copy for turning it on versus off. */
  confirmToggleDigest(row: SavedSearchDto): void {
    const enabling = !row.includeInDigest;
    const data: ConfirmData = enabling
      ? {
          title: this.i18n.translate('reader.digest.enableConfirm'),
          message: this.i18n.translate('reader.digest.enableConfirmMessage', {
            term: row.term,
          }),
          confirmLabel: this.i18n.translate('reader.digest.enableConfirmAction'),
        }
      : {
          title: this.i18n.translate('reader.digest.disableConfirm'),
          message: this.i18n.translate('reader.digest.disableConfirmMessage', {
            term: row.term,
          }),
          confirmLabel: this.i18n.translate('reader.digest.disableConfirmAction'),
        };
    this.confirm.confirmThen(data, () =>
      this.savedSearchesStore.setIncludeInDigest(row.id, enabling),
    );
  }
}
