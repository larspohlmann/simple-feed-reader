import { Injectable, inject } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { Observable } from 'rxjs';
import { FeedAccountRefDto } from '../../social-feed';
import { SocialImportDialogComponent } from './social-import-dialog.component';
import { SocialImportDialogData } from './social-import-list';
import { SocialImportResult } from './social-import.service';

@Injectable({ providedIn: 'root' })
export class SocialFeedsPicker {
  private readonly dialog = inject(Dialog);

  open(
    account: Pick<FeedAccountRefDto, 'id' | 'network' | 'handle'>,
  ): Observable<SocialImportResult | undefined> {
    const data: SocialImportDialogData = {
      accountId: account.id,
      network: account.network,
      handle: account.handle,
    };
    return this.dialog.open<SocialImportResult | undefined>(SocialImportDialogComponent, {
      data,
      panelClass: 'app-dialog',
    }).closed;
  }
}
