// src/app/reader/search-field/search-field.component.ts
import {
  Component,
  DestroyRef,
  computed,
  effect,
  inject,
  input,
  output,
  signal,
  untracked,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Subject, debounceTime } from 'rxjs';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { MIN_SEARCH_LENGTH } from '../query';

const DEBOUNCE_MS = 300;

/**
 * The entry-search input. It owns the debounce and the minimum-length floor,
 * so no parent repeats either rule — it emits only a settled, trimmed term
 * (or the empty string on clear), and the caller's only job is to navigate.
 */
@Component({
  selector: 'app-search-field',
  imports: [TranslocoPipe, IconComponent],
  templateUrl: './search-field.component.html',
  styleUrl: './search-field.component.scss',
})
export class SearchFieldComponent {
  /** The active search term, e.g. restored from the URL on Back navigation. */
  readonly term = input('');
  /** The settled, trimmed term, or '' when the field is cleared. */
  // Semantic "settled search term" output, not a DOM element's search event.
  // eslint-disable-next-line @angular-eslint/no-output-native
  readonly search = output<string>();

  private readonly minLength = MIN_SEARCH_LENGTH;
  /** What the field currently shows — updates on every keystroke, unlike the
   *  debounced `search` output, so the too-short hint reacts immediately. */
  readonly text = signal('');

  readonly tooShort = computed(() => {
    const length = this.text().trim().length;
    return length > 0 && length < this.minLength;
  });

  private readonly typed = new Subject<string>();
  private readonly destroyRef = inject(DestroyRef);
  /** The app's currently active term — not "what this instance last emitted",
   *  but the term in effect right now, from either direction: it moves when
   *  this component emits a settled term, and it moves when the `term` input
   *  arrives from outside (Back/Forward, or any other route change with the
   *  sidebar still mounted). That symmetry is what keeps a debounce burst
   *  that settles on a repeat from being sent twice, while also keeping a
   *  value re-typed after the route moved on without this component's help
   *  from being wrongly swallowed as a false repeat. Only `emitSettled()`
   *  reads this field to dedup; `clear()` always emits regardless of its
   *  value, so an Escape or a click on Clear never goes missing even when
   *  nothing the user typed had settled into it yet. Kept as a field rather
   *  than an RxJS `distinctUntilChanged()` because that operator only sees
   *  values reaching the debounced pipeline, and both `clear()` and the
   *  external-term effect deliberately bypass it. A debounce already in
   *  flight when an external change lands can still fire once more with the
   *  older typed value, briefly flipping the route back; that resolves
   *  itself through the same round trip once the effect re-syncs, so it is
   *  left alone rather than guarded against. */
  private activeTerm = '';

  constructor() {
    // Keep the field in step with a term the caller sets from outside (Back
    // navigation restoring a prior search, or moving to a different one)
    // without feeding it back through the debounce as if the user had just
    // typed it. activeTerm moves with it: the route, not this instance's own
    // emission history, is the source of truth for what is "already active",
    // so typing that same term again later is never mistaken for a repeat.
    effect(() => {
      const external = this.term();
      untracked(() => {
        this.text.set(external);
        this.activeTerm = external;
      });
    });

    this.typed
      .pipe(debounceTime(DEBOUNCE_MS), takeUntilDestroyed(this.destroyRef))
      .subscribe((value) => this.emitSettled(value));
  }

  onInput(value: string): void {
    this.text.set(value);
    this.typed.next(value);
  }

  /** Leaving a search should not lag, so clearing bypasses both the debounce
   *  and the settled path's dedup check — an empty box always emits '', even
   *  when nothing the user typed had actually settled into the active term
   *  yet (e.g. Escape right after typing, before the debounce fired). */
  clear(): void {
    this.text.set('');
    this.activeTerm = '';
    this.search.emit('');
    // Supersede a pending debounce, not to emit through it: without this, a
    // debounce started just before the clear still fires ~300 ms later with
    // the old typed value, and the cleared search reappears on its own.
    this.typed.next('');
  }

  onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && this.text() !== '') this.clear();
  }

  private emitSettled(raw: string): void {
    const trimmed = raw.trim();
    if (trimmed.length > 0 && trimmed.length < this.minLength) return;
    if (trimmed === this.activeTerm) return;
    this.activeTerm = trimmed;
    this.search.emit(trimmed);
  }
}
