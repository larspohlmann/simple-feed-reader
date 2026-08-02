// src/app/reader/header/reader-header.component.ts
import { Component, inject, input, output, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { TagGlyphComponent } from '../../shared/tag-glyph/tag-glyph.component';
import { SpinnerComponent } from '../../shared/spinner/spinner.component';
import { UserAvatarComponent } from '../../shared/user-avatar/user-avatar.component';
import { DismissOnOutsideDirective } from '../../shared/dismiss-on-outside.directive';
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

  readonly auth = inject(AuthService);
  readonly refreshSvc = inject(RefreshService);
  readonly screen = inject(LayoutService);
  readonly menuOpen = signal(false);
}
