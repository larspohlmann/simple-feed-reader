// src/app/reader/search-field/search-field.component.ts
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
 * The entry-search input. It owns the debounce and the minimum-length floor,
 * so no parent repeats either rule — it emits only a settled, trimmed term
 * (or the empty string when the search ends), and the caller's only job is to
 * navigate.
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
  /** Whether this field can be left at all — which is what gives it a second
   *  step, and so decides both whether the trailing ✕ survives an empty box
   *  and what emptying that box means. The mobile header bar sets it, because
   *  there the ✕ is the whole exit: a phone has no Escape key, and the bar
   *  deliberately carries no close button of its own beside the field's
   *  (#550 — two ✕ side by side, one clearing and one closing, read as one
   *  control that behaved differently depending on where it was tapped). The
   *  sidebar's copy is permanent, has nothing to leave, and so keeps the ✕
   *  only while there is text to clear. `dismissed` still fires from Escape
   *  regardless of this flag. */
  readonly dismissible = input(false);
  /** The user asked to leave the search with nothing left to clear — the
   *  second step of the two-step contract, reached either by Escape or by the
   *  trailing ✕ of a dismissible field. The field knows nothing about what
   *  "leaves" means for its caller; it only reports that there was nothing
   *  left to clear. */
  readonly dismissed = output<void>();

  /** What the field currently shows — updates on every keystroke, unlike the
   *  debounced `search` output, so the too-short hint reacts immediately. */
  readonly text = signal('');

  readonly tooShort = computed(() => isTooShortToSearch(this.text()));
  /** Which step of the two-step exit the field is in. One predicate for all
   *  three readers — the trailing button's visibility, its label, and what a
   *  click on it does — so a later change to what counts as "nothing left to
   *  clear" cannot leave the ✕ announcing one thing and doing the other.
   *  Deliberately not "is a search running": an emptied box in a field that
   *  can be left still has results behind it, and that is the whole point of
   *  the first step. */
  readonly hasTextToClear = computed(() => this.text() !== '');
  /** What emptying the box means here — the one rule the two mounts do not
   *  share. A field that can be left has a second step to end the search on
   *  the way out, so emptying its box is only that: the results stay up while
   *  the user decides whether to retype or to go. A permanent field has no
   *  second step, so an emptied box is the only thing its ✕ can ever mean, and
   *  it has to mean it at once. One predicate for both readers — the ✕ and the
   *  debounced settle path — because the failure mode is the button and the
   *  keyboard disagreeing about what an empty box means. */
  private readonly emptyingEndsSearch = computed(() => !this.dismissible());

  private readonly inputEl = viewChild<ElementRef<HTMLInputElement>>('inputEl');

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
   *  reads this field to dedup; `endSearch()` reports the end regardless of
   *  its value, so an Escape or a click on the ✕ never goes missing even
   *  when nothing the user typed had settled into it yet. Kept as a field
   *  rather than an RxJS `distinctUntilChanged()` because that operator only
   *  sees values reaching the debounced pipeline, and both `endSearch()` and
   *  the external-term effect deliberately bypass it. */
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

  /** Firefox opens quick-find on a bare `/`; this replaces that, so it only
   *  claims the key when it actually takes it — never when a modifier turns
   *  it into someone else's shortcut, and never when it would steal a slash
   *  mid-sentence from a text field (including this one's own input). */
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

  /** Ends the search: empties the box and tells the caller there is no term
   *  any more. Leaving a search should not lag, so this bypasses the debounce,
   *  and it bypasses the settled path's dedup so the end is reported even when
   *  nothing the user typed had settled into the active term yet (Escape or ✕
   *  right after typing). Silent when there is nothing to end — an empty box
   *  over an unsearched list — so closing a bar that never searched does not
   *  navigate. */
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
    // words instead of substrings (#408 follow-up), one mode for the whole
    // query, so it must reach the request unchanged. Only the meaningless
    // whitespace — leading, and runs collapsed between terms — is removed.
    const normalized = normalizeSearchInput(raw);
    // A half-typed term is not a search yet; an empty one is a real event
    // (it ends the search), so it must fall through.
    if (isTooShortToSearch(normalized)) return;
    // …but only where an emptied box is the end of the search. Where it is
    // not, the same rule has to hold for backspace as for the ✕ — see
    // `emptyingEndsSearch`.
    if (normalized === '' && !this.emptyingEndsSearch()) return;
    // 'punk' and 'punk ' are different searches now (substring vs. whole
    // word), so this dedup — which exists to stop a debounce burst that
    // settles on a genuine repeat — must compare the raw normalized string,
    // not its trimmed form, or adding the trailing space would be silently
    // swallowed as "no change".
    if (normalized === this.activeTerm) return;
    this.activeTerm = normalized;
    this.search.emit(normalized);
  }
}
