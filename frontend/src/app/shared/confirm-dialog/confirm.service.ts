import { Injectable, inject } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { Observable, map } from 'rxjs';
import { ConfirmData, ConfirmDialogComponent } from './confirm-dialog.component';

@Injectable({ providedIn: 'root' })
export class ConfirmService {
  private readonly dialog = inject(Dialog);

  ask(data: ConfirmData): Observable<boolean> {
    return this.dialog
      .open<boolean>(ConfirmDialogComponent, { data, role: 'alertdialog', panelClass: 'app-dialog' })
      .closed.pipe(map((confirmed) => confirmed === true));
  }

  confirmThen(data: ConfirmData, action: () => void): void {
    this.ask(data).subscribe((confirmed) => {
      if (confirmed) action();
    });
  }
}
