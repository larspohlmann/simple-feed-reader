import { Injectable, inject, signal } from '@angular/core';
import { Observable, map, tap } from 'rxjs';
import { onIdentityChange } from '../../core/auth/session-identity';
import { StaleGatedLoad } from '../state/stale-gated-load';
import { LabelsApi } from './labels.api';
import { LabelPill, LabelTally, UnlabeledTally } from './labels.models';

const NO_UNLABELED: UnlabeledTally = { favoriteCount: 0, keptCount: 0 };

/** The reader's labels, alphabetical, with how many favorites and kept posts each sorts, and how many carry none.
 *  Every change to a label reloads the tallies; a row's own labels change through `EntryActionHandler`. */
@Injectable({ providedIn: 'root' })
export class LabelsStore {
  private readonly api = inject(LabelsApi);
  private readonly loads = new StaleGatedLoad();
  private readonly storedLabels = signal<LabelTally[]>([]);
  private readonly storedUnlabeled = signal<UnlabeledTally>(NO_UNLABELED);

  readonly labels = this.storedLabels.asReadonly();
  readonly unlabeled = this.storedUnlabeled.asReadonly();

  constructor() {
    onIdentityChange(() => {
      this.loads.forget();
      this.storedLabels.set([]);
      this.storedUnlabeled.set(NO_UNLABELED);
    });
  }

  reload(): void {
    this.loads.run(this.api.tallies(), (response) => {
      this.storedLabels.set(response.labels);
      this.storedUnlabeled.set(response.unlabeled);
    });
  }

  create(name: string): Observable<LabelPill> {
    return this.api.create(name).pipe(tap(() => this.reload()));
  }

  rename(id: number, name: string): Observable<void> {
    return this.reloadAfter(this.api.rename(id, name));
  }

  remove(id: number): Observable<void> {
    return this.reloadAfter(this.api.remove(id));
  }

  attach(entryId: number, labelId: number): Observable<void> {
    return this.api.attach(entryId, labelId);
  }

  detach(entryId: number, labelId: number): Observable<void> {
    return this.api.detach(entryId, labelId);
  }

  private reloadAfter(write: Observable<unknown>): Observable<void> {
    return write.pipe(
      tap(() => this.reload()),
      map(() => undefined),
    );
  }
}
