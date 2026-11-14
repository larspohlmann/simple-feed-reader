import { Injectable, inject } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { Observable, of } from 'rxjs';
import { isSocialNetworkName } from '../../../core/social-network';
import { SocialAccountRefDto } from '../../social-feed';
import { SocialImportDialogComponent } from './social-import-dialog.component';
import { SocialImportDialogData } from './social-import-list';
import { SocialImportResult } from './social-import.service';

@Injectable({ providedIn: 'root' })
export class SocialFeedsPicker {
  private readonly dialog = inject(Dialog);

  open(
    account: Pick<SocialAccountRefDto, 'id' | 'network' | 'handle'>,
  ): Observable<SocialImportResult | undefined> {
    const { network } = account;
    if (!isSocialNetworkName(network)) return of(undefined);

    const data: SocialImportDialogData = {
      accountId: account.id,
      network,
      handle: account.handle,
    };
    return this.dialog.open<SocialImportResult | undefined>(SocialImportDialogComponent, {
      data,
      panelClass: 'app-dialog',
    }).closed;
  }
}
