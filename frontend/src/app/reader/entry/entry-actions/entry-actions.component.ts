import { Component, DestroyRef, ElementRef, computed, inject, input } from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { EntryActionHandler } from './entry-action-handler';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent, IconSize } from '../../../shared/icon/icon.component';
import { FlagToggleDirective } from '../../../shared/flag-toggle/flag-toggle.directive';
import { EntryDto } from '../../models';
import { AudioPlayerService } from '../../audio-player.service';
import { EntryAudio } from '../../audio/entry-audio';
import { EntryLikeComponent } from '../entry-like/entry-like.component';
import { ListenLauncher } from '../../audio/listen-launcher';
import { LabelMenu } from '../../labels/label-menu.service';
import { isLabellable } from '../../labels/labellable';

/** The per-entry action cluster; its handlers stop propagation but never `preventDefault()`, which would cancel the button's own activation. */
@Component({
  selector: 'app-entry-actions',
  imports: [
    IconComponent,
    EntryLikeComponent,
    TranslocoPipe,
    FlagToggleDirective,
    NgTemplateOutlet,
  ],
  templateUrl: './entry-actions.component.html',
  styleUrl: './entry-actions.component.scss',
  host: { '[class.glyph-md]': "size() === 'md'" },
})
export class EntryActionsComponent {
  readonly entry = input.required<EntryDto>();
  /** Glyph size for the icons. The standard list (`entry-row`) renders
   *  `md`; magazine blocks keep `sm`. Only these two are offered — the
   *  tap-target math is defined for both (see `glyph-md` in the stylesheet). */
  readonly size = input<Extract<IconSize, 'sm' | 'md'>>('sm');
  /** Off in the article view, whose own Listen row offers the same two controls. */
  readonly audio = input(true);
  readonly like = input<'first' | 'after-favorite' | 'none'>('after-favorite');
  /** Where the label button of a favorite or kept entry goes: `list` leads the star, `article` follows the tick. */
  readonly labelMenu = input<'list' | 'article'>('list');
  protected readonly entryAudio = new EntryAudio(
    { entry: this.entry },
    inject(AudioPlayerService),
    inject(ListenLauncher),
  );
  protected readonly actions = inject(EntryActionHandler);
  protected readonly labelPicker = inject(LabelMenu);
  protected readonly labelsShown = computed(() => isLabellable(this.entry()));

  constructor() {
    const host: HTMLElement = inject(ElementRef).nativeElement;
    inject(DestroyRef).onDestroy(() => this.labelPicker.closeWithin(host));
  }
}
