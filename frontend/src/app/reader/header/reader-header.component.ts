// src/app/reader/header/reader-header.component.ts
import {
  ChangeDetectorRef,
  Component,
  ElementRef,
  afterRenderEffect,
  effect,
  inject,
  input,
  output,
  signal,
  viewChild,
} from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { TagGlyphComponent } from '../../shared/tag-glyph/tag-glyph.component';
import { SpinnerComponent } from '../../shared/spinner/spinner.component';
import { UserAvatarComponent } from '../../shared/user-avatar/user-avatar.component';
import { DismissOnOutsideDirective } from '../../shared/dismiss-on-outside.directive';
import { SearchFieldComponent } from '../search-field/search-field.component';
import { ForYouProgressComponent } from '../for-you-progress/for-you-progress.component';
import { AuthService } from '../../core/auth.service';
import { RefreshService } from '../refresh.service';
import { LayoutService } from '../layout.service';
import { TagDto } from '../models';
import { selectionQueryParams } from '../query';

/**
 * The app bar — the LIST's chrome, and only the list's. A full-screen article
 * is a layer above it with its own toolbar, so this bar never changes shape or
 * content when one opens (#128).
 */
@Component({
  selector: 'app-reader-header',
  imports: [
    IconComponent,
    TagGlyphComponent,
    RouterLink,
    TranslocoPipe,
    UserAvatarComponent,
    SpinnerComponent,
    DismissOnOutsideDirective,
    SearchFieldComponent,
    ForYouProgressComponent,
  ],
  templateUrl: './reader-header.component.html',
  styleUrl: './reader-header.component.scss',
})
export class ReaderHeaderComponent {
  protected readonly selectionQueryParams = selectionQueryParams;

  /** Tags for the mobile swipe row; empty hides the row (and on wider screens
   *  CSS hides it regardless). */
  readonly tags = input<TagDto[]>([]);
  readonly activeTagId = input<number | null>(null);
  /** Whether the list currently shows All Items, for the leading pill's highlight.
   *  The bar cannot derive this: `activeTagId` is null for Favorites, Kept and a
   *  single feed as well, so only the shell knows. */
  readonly allItemsActive = input(false);
  /** A search request is in flight; forwarded to the mobile bar's own
   *  `app-search-field`. */
  readonly searchLoading = input(false);
  /** The term the route currently carries, forwarded to the mobile bar's own
   *  `app-search-field` exactly as the sidebar forwards it to its copy. The
   *  field is mounted in two places and each mount has to be wired the same
   *  way; without this the narrow layout opened its bar empty while the
   *  results for `?q=` were on screen — after a reload, or after Back
   *  returned to an earlier search. */
  readonly searchTerm = input('');

  readonly toggleSidebar = output<void>();
  /** The empty middle of the bar was tapped — scroll the list back to the top. */
  readonly scrollTop = output<void>();
  /** The settled search term from the field, or '' when it is cleared. */
  // Semantic "settled search term" output, not a DOM element's search event.
  // eslint-disable-next-line @angular-eslint/no-output-native
  readonly search = output<string>();

  readonly auth = inject(AuthService);
  readonly refreshSvc = inject(RefreshService);
  readonly screen = inject(LayoutService);
  private readonly changeDetector = inject(ChangeDetectorRef);
  readonly menuOpen = signal(false);
  /** Whether the mobile bar covers the header. The shell reads this to force
   *  the header on-screen (see reader-shell.component.ts) — the bar holds the
   *  live term and the phone's keyboard, so scrolling it away would hide the
   *  text the results depend on. */
  readonly searchOpen = signal(false);

  private readonly searchFieldHost = viewChild('searchField', { read: ElementRef });
  private readonly searchTrigger = viewChild('searchTrigger', { read: ElementRef });
  /** Skips the effect's very first run (mount, `searchOpen` still false) —
   *  otherwise a page load would yank focus onto the (not yet interacted with)
   *  search trigger before the user asked for anything. */
  private isFirstFocusRun = true;
  /** Set just before the layout effect below force-closes the bar, so the
   *  focus effect can tell "the window grew out from under the user" apart
   *  from "the user dismissed the bar" — see the focus effect's own comment
   *  for why that distinction matters. */
  private closedByLayout = false;

  constructor() {
    // `searchOpen` is local state the narrow-only trigger sets true; nothing
    // else ever moves it. Growing past NARROW_QUERY mid-search (rotate, or
    // resize a resizable window) leaves it stuck true, so `app-sidebar`'s own
    // `!isNarrow()` gate mounts a second `app-search-field` — a second `/`
    // listener, and the mobile bar still covering the header — on a layout
    // that no longer has a trigger for it. This is the only place that opens
    // the bar, so it is also the only place responsible for closing it when
    // the layout it belongs to goes away.
    effect(() => {
      if (this.screen.isNarrow() || !this.searchOpen()) return;
      this.closedByLayout = true;
      this.searchOpen.set(false);
    });

    // Move focus with the bar: opening it swaps the trigger out for the field
    // (the trigger unmounts), and closing it swaps the field back out for the
    // trigger, so leaving focus behind either way would drop it to <body> for
    // a keyboard or screen-reader user. `afterRenderEffect` (not a plain
    // `effect`) because the target element must already exist in the DOM —
    // the `@if` branch that holds it is still applying when a constructor
    // `effect` would fire.
    afterRenderEffect(() => {
      const open = this.searchOpen();
      if (this.isFirstFocusRun) {
        this.isFirstFocusRun = false;
        return;
      }
      if (open) {
        this.searchFieldHost()?.nativeElement.querySelector('input')?.focus();
        return;
      }
      // A user-initiated close (the field's trailing ✕, or Escape — both
      // arrive as `dismissed`) always has a trigger button back on screen to
      // receive focus. A
      // layout-initiated close does not: the trigger is itself narrow-gated,
      // so it is absent on exactly the wide layout this close lands on.
      // Chasing focus onto a nonexistent element would only drop it to
      // <body> anyway, so it is left wherever the resize/rotate found it.
      if (this.closedByLayout) {
        this.closedByLayout = false;
        return;
      }
      this.searchTrigger()?.nativeElement.focus();
    });
  }

  /**
   * Opens the mobile search bar and puts the cursor in the field within the tap
   * itself. The trailing `afterRenderEffect` above also focuses on open, but it
   * runs a tick later — outside the gesture — and iOS/Android only raise the
   * soft keyboard for a `focus()` that happens inside the user gesture. So the
   * field is rendered synchronously here (`detectChanges`, which materialises
   * the `@else` branch that holds it) and focused in the same call stack, which
   * is what actually opens the keyboard on a phone (#486).
   */
  openSearch(): void {
    this.searchOpen.set(true);
    // `detectChanges()` refreshes the view query, so `searchFieldHost` now
    // resolves to the just-rendered field — the same handle the focus effect
    // above uses on open.
    this.changeDetector.detectChanges();
    this.searchFieldHost()?.nativeElement.querySelector('input')?.focus();
  }

  closeSearch(): void {
    this.searchOpen.set(false);
  }
}
