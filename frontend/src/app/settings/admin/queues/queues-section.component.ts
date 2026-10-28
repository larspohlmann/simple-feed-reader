import { HttpErrorResponse } from '@angular/common/http';
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { AdminApi } from '../../../admin/admin-api';
import { QueueDepthDto } from '../../../admin/admin.models';
import { Problem, parseProblem } from '../../../core/problem';
import { LanguageService } from '../../../core/i18n/language.service';
import { relativeTime } from '../../../reader/format';
import { ButtonComponent } from '../../../shared/button/button.component';
import { ErrorBannerComponent } from '../../../shared/error-banner/error-banner.component';
import { SettingsGroupComponent } from '../../../shared/settings/settings-group/settings-group.component';
import { SettingsStackComponent } from '../../../shared/settings/stack/settings-stack.component';
import { SkeletonComponent } from '../../../shared/skeleton/skeleton.component';

@Component({
  selector: 'app-queues-section',
  imports: [
    ButtonComponent,
    ErrorBannerComponent,
    SettingsGroupComponent,
    SettingsStackComponent,
    SkeletonComponent,
    TranslocoPipe,
  ],
  templateUrl: './queues-section.component.html',
  styleUrl: './queues-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class QueuesSectionComponent {
  private readonly api = inject(AdminApi);
  private readonly language = inject(LanguageService);

  readonly queues = signal<QueueDepthDto[]>([]);
  readonly sortedQueues = computed(() =>
    [...this.queues()].sort((left, right) => right.waiting - left.waiting),
  );
  readonly loading = signal(false);
  readonly error = signal<Problem | null>(null);

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.queues().subscribe({
      next: (response) => {
        this.queues.set(response.queues);
        this.loading.set(false);
      },
      error: (failure: HttpErrorResponse) => {
        this.error.set(parseProblem(failure));
        this.loading.set(false);
      },
    });
  }

  oldest(queue: QueueDepthDto): string {
    return queue.oldestQueuedAt === null
      ? '—'
      : relativeTime(queue.oldestQueuedAt, this.language.lang());
  }
}
