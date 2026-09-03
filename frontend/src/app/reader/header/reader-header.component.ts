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
import { ProgressHairlineComponent } from '../../shared/progress-hairline/progress-hairline.component';
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
    ProgressHairlineComponent,
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
  /** The term the route carries, forwarded to the mobile bar's `app-search-field`
   *  exactly as the sidebar forwards to its copy — both mounts need the same
   *  wiring, or the narrow bar opens empty while `?q=` results are on screen
   *  (after a reload, or after Back). */
  readonly searchTerm = input('');
  readonly populateSearchTerm = input(true);

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
   *  the header on-screen — the bar holds the live term and keyboard, so
   *  scrolling it away would hide the text results depend on. */
  readonly searchOpen = signal(false);

  private readonly searchFieldHost = viewChild('searchField', { read: ElementRef });
  private readonly searchTrigger = viewChild('searchTrigger', { read: ElementRef });
  /** Skips the effect's very first run (mount, `searchOpen` still false) —
   *  otherwise a page load would yank focus onto the (not yet interacted with)
   *  search trigger before the user asked for anything. */
  private isFirstFocusRun = true;
  /** Set just before the layout effect force-closes the bar, so the focus
   *  effect can tell "the window grew out from under the user" apart from
   *  "the user dismissed the bar" (see that effect's own comment). */
  private closedByLayout = false;

  constructor() {
    // `searchOpen` is local state only the narrow trigger sets true. Growing
    // past NARROW_QUERY mid-search leaves it stuck, so `app-sidebar`'s
    // `!isNarrow()` gate mounts a second field/`/` listener on a layout with no
    // trigger for it — so this is also the only place responsible for closing it.
    effect(() => {
      if (this.screen.isNarrow() || !this.searchOpen()) return;
      this.closedByLayout = true;
      this.searchOpen.set(false);
    });

    // Move focus with the bar: it swaps trigger for field and back, so leaving
    // focus behind either way would drop it to <body>. `afterRenderEffect`, not
    // a plain `effect`, because the target must already exist in the DOM — a
    // constructor `effect` fires while the `@if` branch is still applying.
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
      // A user-initiated close (✕ or Escape, both arrive as `dismissed`)
      // always has a trigger button on screen. A layout-initiated close
      // doesn't — the trigger is itself narrow-gated and absent on this wide
      // layout — so focus is left wherever the resize/rotate found it.
      if (this.closedByLayout) {
        this.closedByLayout = false;
        return;
      }
      this.searchTrigger()?.nativeElement.focus();
    });
  }

  /**
   * Opens the mobile search bar and focuses the field within the tap itself —
   * iOS/Android only raise the soft keyboard for a `focus()` inside the user
   * gesture, so the field is rendered synchronously (`detectChanges`) and
   * focused in the same call stack (#486).
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
