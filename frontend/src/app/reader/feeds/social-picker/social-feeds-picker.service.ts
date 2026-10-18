import { Injectable, inject } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { Observable } from 'rxjs';
import { SocialNetworkName } from '../../../core/social-network';
import {
  SocialImportDialogComponent,
  SocialImportDialogData,
} from './social-import-dialog.component';
import { SocialImportResult } from './social-import.service';

export interface PickerAccount {
  readonly id: number;
  readonly network: SocialNetworkName;
  readonly handle: string;
}

/** Opens one account's Manage feeds picker, from settings or the sidebar. */
@Injectable({ providedIn: 'root' })
export class SocialFeedsPicker {
  private readonly dialog = inject(Dialog);

  open(account: PickerAccount): Observable<SocialImportResult | undefined> {
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
