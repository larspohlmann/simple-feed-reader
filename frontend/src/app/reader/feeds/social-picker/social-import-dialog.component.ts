import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { A11yModule } from '@angular/cdk/a11y';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { RefreshService } from '../../state/refresh.service';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { TagsStore } from '../../state/tags.store';
import { ButtonComponent } from '../../../shared/button/button.component';
import { ErrorBannerComponent } from '../../../shared/error-banner/error-banner.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { inputValue } from '../../../shared/input-value';
import { AvatarComponent } from '../../../shared/avatar/avatar.component';
import { OverlayPanelComponent } from '../../../shared/overlay-panel/overlay-panel.component';
import { SegmentedChoiceComponent } from '../../../shared/segmented-choice/segmented-choice.component';
import { SkeletonComponent } from '../../../shared/skeleton/skeleton.component';
import { CONFIRMATION_DURATION_MS, ToastService } from '../../../shared/toast/toast.service';
import { WarningBoxComponent } from '../../../shared/warning-box/warning-box.component';
import { SocialSearchTabComponent } from './social-search-tab.component';
import { SocialImportDialogData, SocialImportList } from './social-import-list';
import { FILTER_KEYS, TAB_TEST_IDS } from './social-import-rows';
import {
  ImportCandidateState,
  SocialImportResult,
  SocialImportSelection,
  SocialImportService,
} from './social-import.service';

const BADGE_CLASSES: Readonly<Record<ImportCandidateState, string>> = {
  new: 'accent-pill',
  subscribed: 'state',
  no_longer_followed: 'state is-gone',
};

@Component({
  selector: 'app-social-import-dialog',
  imports: [
    A11yModule,
    AvatarComponent,
    ButtonComponent,
    ErrorBannerComponent,
    IconComponent,
    OverlayPanelComponent,
    SegmentedChoiceComponent,
    SkeletonComponent,
    SocialSearchTabComponent,
    TranslocoPipe,
    WarningBoxComponent,
  ],
  providers: [SocialImportService, SocialImportList],
  templateUrl: './social-import-dialog.component.html',
  styleUrl: './social-import-dialog.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SocialImportDialogComponent {
  private readonly dialogRef = inject<DialogRef<SocialImportResult | undefined>>(DialogRef);
  protected readonly data = inject<SocialImportDialogData>(DIALOG_DATA);
  protected readonly importer = inject(SocialImportService);
  protected readonly list = inject(SocialImportList);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly tags = inject(TagsStore);
  private readonly refresh = inject(RefreshService);
  private readonly toast = inject(ToastService);
  private readonly i18n = inject(TranslocoService);

  protected readonly filterKeys = FILTER_KEYS;
  protected readonly badgeClasses = BADGE_CLASSES;
  protected readonly tabTestIds = TAB_TEST_IDS;
  protected readonly inputValue = inputValue;

  constructor() {
    this.load();
  }

  protected load(): void {
    this.importer.load(this.data.accountId);
  }

  protected apply(): void {
    this.list.apply((selection) => this.send(selection));
  }

  protected subscribeSearch(query: string): void {
    this.send({
      subscribe: [],
      unsubscribe: [],
      collections: [{ kind: 'search', id: query, name: query }],
    });
  }

  protected cancel(): void {
    this.dialogRef.close(undefined);
  }

  private send(selection: SocialImportSelection): void {
    this.importer.importSelection(this.data.accountId, selection).subscribe({
      next: (result) => this.finish(result),
      error: () => undefined,
    });
  }

  private finish(result: SocialImportResult): void {
    this.announce(result);
    this.subscriptions.load();
    this.tags.load();
    if (result.imported > 0) {
      this.refresh.run(() => this.subscriptions.load());
    }
    this.dialogRef.close(result);
  }

  private announce(result: SocialImportResult): void {
    const done = this.i18n.translate('settings.social.importDone', {
      imported: result.imported,
      removed: result.removed,
      alreadySubscribed: result.alreadySubscribed,
    });
    const overLimit =
      result.skippedOverLimit > 0
        ? ' ' +
          this.i18n.translate('settings.social.importOverLimit', {
            count: result.skippedOverLimit,
          })
        : '';
    this.toast.show({ message: done + overLimit, durationMs: CONFIRMATION_DURATION_MS });
  }
}
