import {
  Component,
  DestroyRef,
  ElementRef,
  HostListener,
  computed,
  effect,
  inject,
  input,
  output,
  signal,
  untracked,
  viewChild,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Subject, debounceTime } from 'rxjs';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { SpinnerComponent } from '../../shared/spinner/spinner.component';
import { isTooShortToSearch, normalizeSearchInput } from '../query';

const DEBOUNCE_MS = 300;

/** Elements a bare `/` must type into rather than be stolen from. Matches the
 *  target itself, not `document.activeElement` by name, so a `/` typed inside
 *  this very field's own input takes this branch and is left alone. */
function isTextEntryTarget(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) return false;
  return target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable;
}

/**
 * The entry-search input. It owns the debounce and minimum-length floor, so no
 * parent repeats either rule — it emits only a settled, trimmed term (or '' when
 * the search ends), and the caller's only job is to navigate.
 */
@Component({
  selector: 'app-search-field',
  imports: [TranslocoPipe, IconComponent, SpinnerComponent],
  templateUrl: './search-field.component.html',
  styleUrl: './search-field.component.scss',
})
export class SearchFieldComponent {
  /** The active search term, e.g. restored from the URL on Back navigation. */
  readonly term = input('');
  readonly populateTerm = input(true);
  /** A search request for this field's own term is in flight. Replaces the
   *  leading search icon with a spinner; the caller is the only one who knows
   *  (the field itself never fetches), so it is always driven from outside. */
  readonly loading = input(false);
  /** The settled, trimmed term, or '' when the search ends. Emptying the box
   *  is not always that — see `emptyingEndsSearch`. */
  // Semantic "settled search term" output, not a DOM element's search event.
  // eslint-disable-next-line @angular-eslint/no-output-native
  readonly search = output<string>();
  /** Whether this field can be left at all — decides if the trailing ✕
   *  survives an empty box and what emptying it means. The mobile header sets
   *  it: there the ✕ is the whole exit (no Escape key, no separate close
   *  button — #550, two ✕s read as one inconsistent control). The sidebar's
   *  permanent copy keeps the ✕ only while there's text to clear; `dismissed`
   *  still fires from Escape regardless. */
  readonly dismissible = input(false);
  /** The user asked to leave the search with nothing left to clear — the
   *  second step of the two-step contract (Escape, or the trailing ✕ of a
   *  dismissible field). The field doesn't know what "leaves" means to its caller. */
  readonly dismissed = output<void>();

  /** What the field currently shows — updates on every keystroke, unlike the
   *  debounced `search` output, so the too-short hint reacts immediately. */
  readonly text = signal('');

  readonly tooShort = computed(() => isTooShortToSearch(this.text()));
  /** Which step of the two-step exit the field is in — one predicate for the
   *  trailing button's visibility, label, and click behavior, so they can't
   *  drift apart. Deliberately not "is a search running": an emptied box in a
   *  leavable field still has results behind it. */
  readonly hasTextToClear = computed(() => this.text() !== '');
  /** What emptying the box means — the one rule the two mounts don't share. A
   *  leavable field has a second step, so emptying is only that (results stay
   *  up); a permanent field has none, so emptying means it at once. One
   *  predicate for both the ✕ and the debounce path, so they can't disagree. */
  private readonly emptyingEndsSearch = computed(() => !this.dismissible());

  private readonly inputEl = viewChild<ElementRef<HTMLInputElement>>('inputEl');

  private readonly typed = new Subject<string>();
  private readonly destroyRef = inject(DestroyRef);
  /** The app's currently active term, updated from either direction — this
   *  component's own emit, or the `term` input arriving from outside
   *  (Back/Forward). That symmetry stops a debounce burst settling on a repeat
   *  from double-sending, without swallowing a value re-typed after an
   *  external route change. Only `emitSettled()` dedups against it;
   *  `endSearch()` always reports regardless. Kept as a field, not
   *  `distinctUntilChanged()`, since both bypass the debounced pipeline. */
  private activeTerm = '';

  constructor() {
    effect(() => {
      // Track saved terms too, so switching saved searches cancels pending input.
      const term = this.term();
      const external = this.populateTerm() ? term : '';
      untracked(() => {
        this.text.set(external);
        this.activeTerm = external;
      });
    });

    this.typed
      .pipe(debounceTime(DEBOUNCE_MS), takeUntilDestroyed(this.destroyRef))
      .subscribe((value) => this.emitSettled(value));
  }

  /** Firefox opens quick-find on a bare `/`; this replaces that, claiming the
   *  key only when it actually takes it — never with a modifier held, and
   *  never mid-sentence in a text field (including this one). */
  @HostListener('document:keydown', ['$event'])
  onDocumentKeydown(event: KeyboardEvent): void {
    if (event.key !== '/') return;
    if (event.ctrlKey || event.metaKey || event.altKey) return;
    if (isTextEntryTarget(event.target)) return;
    event.preventDefault();
    this.inputEl()?.nativeElement.focus();
  }

  onInput(value: string): void {
    this.text.set(value);
    this.typed.next(value);
  }

  /** Escape and the trailing ✕ are the same two-step contract, so they run the
   *  same code. */
  clearOrDismiss(): void {
    // Step two: nothing left in the box, so this is the way out — drop the
    // search the box no longer shows, then leave. Both, and in that order: the
    // results the first step left standing are what the user is leaving.
    if (!this.hasTextToClear()) {
      this.endSearch();
      this.dismissed.emit();
      return;
    }
    // Step one, and the only place the two mounts part ways. Pulling the
    // results away here is what made the ✕ feel like it had already left (the
    // list underneath changed) without having left.
    if (this.emptyingEndsSearch()) {
      this.endSearch();
      return;
    }
    this.text.set('');
  }

  /** Ends the search: empties the box and tells the caller there's no term any
   *  more, bypassing both the debounce (no lag) and the settled path's dedup
   *  (so Escape/✕ right after typing still reports). Silent when there's
   *  nothing to end. */
  private endSearch(): void {
    if (!this.hasTextToClear() && this.activeTerm === '') return;
    this.text.set('');
    this.activeTerm = '';
    this.search.emit('');
  }

  onKeydown(event: KeyboardEvent): void {
    if (event.key !== 'Escape') return;
    // Stop the key here: a caller wrapping this field in a dismiss-on-Escape
    // popover (the mobile header bar) must not see the first Escape too — that
    // would skip straight to closing instead of clearing first.
    event.preventDefault();
    event.stopPropagation();
    this.clearOrDismiss();
  }

  private emitSettled(raw: string): void {
    // A value that no longer matches the box was superseded while it sat in
    // the debounce — by the ✕, by Escape, or by a term arriving from the route
    // — and searching for it now would contradict what the field shows.
    if (raw !== this.text()) return;
    // Not a plain trim(): a trailing space tells the server to match whole
    // words instead of substrings (#408), so it must reach the request
    // unchanged — only leading whitespace and collapsed inner runs are removed.
    const normalized = normalizeSearchInput(raw);
    // A half-typed term is not a search yet; an empty one is a real event
    // (it ends the search), so it must fall through.
    if (isTooShortToSearch(normalized)) return;
    // …but only where an emptied box is the end of the search. Where it is
    // not, the same rule has to hold for backspace as for the ✕ — see
    // `emptyingEndsSearch`.
    if (normalized === '' && !this.emptyingEndsSearch()) return;
    // 'punk' and 'punk ' are different searches (substring vs. whole word), so
    // this dedup — meant to stop a debounce burst settling on a repeat — must
    // compare the raw normalized string, not trimmed, or the trailing space
    // is silently swallowed.
    if (normalized === this.activeTerm) return;
    this.activeTerm = normalized;
    this.search.emit(normalized);
  }
}
