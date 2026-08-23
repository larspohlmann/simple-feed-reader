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
 * (or the empty string on clear), and the caller's only job is to navigate.
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
  /** A search request for this field's own term is in flight. Replaces the
   *  leading search icon with a spinner; the caller is the only one who knows
   *  (the field itself never fetches), so it is always driven from outside. */
  readonly loading = input(false);
  /** The settled, trimmed term, or '' when the field is cleared. */
  // Semantic "settled search term" output, not a DOM element's search event.
  // eslint-disable-next-line @angular-eslint/no-output-native
  readonly search = output<string>();
  /** Whether this mount is one the user can leave. The mobile header bar is —
   *  it collapses back into the header — so its trailing ✕ stays on screen
   *  with the field empty, as the only way out a finger can reach. The
   *  sidebar's copy is permanent, has nothing to leave, and so keeps the ✕
   *  only while there is text to clear. */
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

  /** The trailing ✕ and Escape are the same two-step contract, so they run the
   *  same code: clear whatever is there, and report a field that was already
   *  empty so the caller can leave. Giving the ✕ this second step is what let
   *  the mobile bar drop the separate close button beside it (#550) — a phone
   *  has no Escape key, so the button is the only step-two a finger has. */
  clearOrDismiss(): void {
    if (this.text() !== '') {
      this.clear();
      return;
    }
    this.dismissed.emit();
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
    // Not a plain trim(): a trailing space tells the server to match whole
    // words instead of substrings (#408 follow-up), one mode for the whole
    // query, so it must reach the request unchanged. Only the meaningless
    // whitespace — leading, and runs collapsed between terms — is removed.
    const normalized = normalizeSearchInput(raw);
    // A half-typed term is not a search yet; an empty one is a real event
    // (it ends the search), so it must fall through.
    if (isTooShortToSearch(normalized)) return;
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
