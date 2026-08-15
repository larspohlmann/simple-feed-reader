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
import { Subject, debounceTime, distinctUntilChanged } from 'rxjs';
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

  readonly minLength = MIN_SEARCH_LENGTH;
  /** What the field currently shows — updates on every keystroke, unlike the
   *  debounced `search` output, so the too-short hint reacts immediately. */
  readonly text = signal('');

  readonly tooShort = computed(() => {
    const length = this.text().trim().length;
    return length > 0 && length < this.minLength;
  });

  private readonly typed = new Subject<string>();
  private readonly destroyRef = inject(DestroyRef);

  constructor() {
    // Keep the field in step with a term the caller sets from outside (Back
    // navigation restoring a prior search) without feeding it back through
    // the debounce as if the user had just typed it.
    effect(() => {
      const external = this.term();
      untracked(() => this.text.set(external));
    });

    this.typed
      .pipe(debounceTime(DEBOUNCE_MS), distinctUntilChanged(), takeUntilDestroyed(this.destroyRef))
      .subscribe((value) => this.emitSettled(value));
  }

  onInput(value: string): void {
    this.text.set(value);
    this.typed.next(value);
  }

  /** Leaving a search should not lag, so clearing bypasses the debounce entirely. */
  clear(): void {
    this.text.set('');
    this.search.emit('');
  }

  onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && this.text() !== '') this.clear();
  }

  private emitSettled(raw: string): void {
    const trimmed = raw.trim();
    if (trimmed.length > 0 && trimmed.length < this.minLength) return;
    this.search.emit(trimmed);
  }
}
