// src/app/reader/header/reader-header.component.ts
import {
  Component,
  ElementRef,
  afterRenderEffect,
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
import { AuthService } from '../../core/auth.service';
import { RefreshService } from '../refresh.service';
import { LayoutService } from '../layout.service';
import { TagDto } from '../models';

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
  ],
  templateUrl: './reader-header.component.html',
  styleUrl: './reader-header.component.scss',
})
export class ReaderHeaderComponent {
  /** Tags for the mobile swipe row; empty hides the row (and on wider screens
   *  CSS hides it regardless). */
  readonly tags = input<TagDto[]>([]);
  readonly activeTagId = input<number | null>(null);
  /** Whether the list currently shows All Items, for the leading pill's highlight.
   *  The bar cannot derive this: `activeTagId` is null for Favorites, Kept and a
   *  single feed as well, so only the shell knows. */
  readonly allItemsActive = input(false);

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

  constructor() {
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
      if (open) this.searchFieldHost()?.nativeElement.querySelector('input')?.focus();
      else this.searchTrigger()?.nativeElement.focus();
    });
  }

  closeSearch(): void {
    this.searchOpen.set(false);
  }
}
