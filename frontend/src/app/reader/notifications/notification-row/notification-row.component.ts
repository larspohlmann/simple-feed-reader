import { OwnInstanceHrefDirective } from '../../own-instance/own-instance-href.directive';
import { NgTemplateOutlet } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { LanguageService } from '../../../core/i18n/language.service';
import { AvatarComponent } from '../../../shared/avatar/avatar.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { entryChangeParams } from '../../query/link-param';
import { relativeTimeNarrow } from '../../format';
import { NotificationGroupDto } from '../notification.models';
import { notificationContext } from '../notification-context';
import { NOTIFICATION_GLYPH, notificationLine } from '../notification-line';

const MAX_AVATARS = 3;

@Component({
  selector: 'app-notification-row',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    OwnInstanceHrefDirective,
    AvatarComponent,
    IconComponent,
    NgTemplateOutlet,
    RouterLink,
    TranslocoPipe,
  ],
  templateUrl: './notification-row.component.html',
  styleUrl: './notification-row.component.scss',
})
export class NotificationRowComponent {
  readonly group = input.required<NotificationGroupDto>();
  readonly showsAccount = input(false);

  private readonly i18n = inject(TranslocoService);
  private readonly language = inject(LanguageService);

  protected readonly entryLink = computed(() => {
    const entryId = this.group().entryId;
    return entryId === null ? null : entryChangeParams(String(entryId));
  });
  protected readonly glyph = computed(() => NOTIFICATION_GLYPH[this.group().kind]);
  protected readonly context = computed(() => notificationContext(this.group()));
  protected readonly avatars = computed(() => this.group().actors.slice(0, MAX_AVATARS));
  protected readonly time = computed(() =>
    relativeTimeNarrow(this.group().latestAt, this.language.lang()),
  );
  protected readonly parts = computed(() => {
    this.language.lang();
    return notificationLine(this.group(), (key, params) => this.i18n.translate(key, params));
  });
}
