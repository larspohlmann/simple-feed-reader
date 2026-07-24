// src/app/reader/header/reader-header.component.ts
import { Component, inject, input, output, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { UserAvatarComponent } from '../../shared/user-avatar/user-avatar.component';
import { AuthService } from '../../core/auth.service';
import { ReaderModeService } from '../reader-mode.service';
import { TagDto } from '../models';

@Component({
  selector: 'app-reader-header',
  imports: [IconComponent, RouterLink, TranslocoPipe, UserAvatarComponent],
  templateUrl: './reader-header.component.html',
  styleUrl: './reader-header.component.scss',
})
export class ReaderHeaderComponent {
  /** True when an article is open full-screen: the bar swaps the brand for a
   *  back button and shows the reader switch and prev/next. */
  readonly articleOpen = input(false);
  readonly hasPrev = input(false);
  readonly hasNext = input(false);
  /** Tags for the mobile swipe row; empty hides the row (and on wider screens
   *  CSS hides it regardless). */
  readonly tags = input<TagDto[]>([]);
  readonly activeTagId = input<number | null>(null);

  readonly toggleSidebar = output<void>();
  readonly prev = output<void>();
  readonly next = output<void>();

  readonly auth = inject(AuthService);
  readonly readerMode = inject(ReaderModeService);
  readonly menuOpen = signal(false);
}
