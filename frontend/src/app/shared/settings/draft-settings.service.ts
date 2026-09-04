import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { computed, inject, signal } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import { Problem, parseProblem } from '../../core/problem';

/**
 * The settled save model for a settings section (#541): toggles and selects
 * persist instantly over the last-saved state, typed fields wait in a draft
 * behind the explicit Save, and one `saved` flag fires on the actual HTTP
 * success so the section toasts once. A subclass names its endpoint and maps
 * server truth to the writable body; everything else is this class.
 *
 * `State` is what the API returns, `Body` what it accepts, and `TypedEdits`
 * the subset of `Body` that the draft may hold.
 */
export abstract class DraftSettingsService<State, Body extends object, TypedEdits extends object> {
  protected readonly http = inject(HttpClient);
  protected readonly base = inject(API_BASE_URL);

  readonly state = signal<State | null>(null);
  readonly busy = signal(false);
  readonly failure = signal<Problem | null>(null);
  readonly saved = signal(false);

  readonly draft = signal<Partial<TypedEdits>>({});
  readonly dirty = computed(() => Object.keys(this.draft()).length > 0);

  protected abstract readonly endpoint: string;

  /** password (where there is one) defaults to null: keep the stored secret. */
  protected abstract bodyFromState(state: State): Body;

  load(): void {
    this.run(this.http.get<State>(this.endpoint), (state) => this.commit(state));
  }

  saveInstant(partial: Partial<Body>): void {
    const current = this.state();
    if (!current) return;
    this.put({ ...this.bodyFromState(current), ...partial }, (state) => {
      this.state.set(state);
      this.saved.set(true);
    });
  }

  setTypedField<F extends keyof TypedEdits>(field: F, value: TypedEdits[F]): void {
    this.draft.update((draft) => ({ ...draft, [field]: value }));
  }

  /**
   * The pending value for one typed field, or undefined when the user has not
   * edited it. Key presence is the test, not nullishness -- a cleared field is
   * a real edit and must win over server truth like any other.
   */
  pending<F extends keyof TypedEdits>(field: F): TypedEdits[F] | undefined {
    const draft = this.draft();

    return field in draft ? draft[field] : undefined;
  }

  /** The explicit Save: last-saved baseline, then `overrides`, then the draft. */
  save(overrides: Partial<Body> = {}): void {
    const current = this.state();
    if (!current) return;
    this.put({ ...this.bodyFromState(current), ...overrides, ...this.draft() }, (state) => {
      this.commit(state);
      this.saved.set(true);
    });
  }

  discardDraft(): void {
    this.draft.set({});
  }

  protected put(body: Body, onSuccess: (state: State) => void): void {
    this.run(this.http.put<State>(this.endpoint, body), onSuccess);
  }

  /** Adopts a server state as the new clean baseline; the draft is now saved. */
  protected commit(state: State): void {
    this.state.set(state);
    this.draft.set({});
  }

  protected run<T>(request: Observable<T>, onSuccess: (value: T) => void): void {
    this.busy.set(true);
    this.failure.set(null);
    this.saved.set(false);
    request.subscribe({
      next: (value) => {
        this.busy.set(false);
        onSuccess(value);
      },
      error: (error: HttpErrorResponse) => {
        this.busy.set(false);
        this.failure.set(parseProblem(error));
      },
    });
  }
}
