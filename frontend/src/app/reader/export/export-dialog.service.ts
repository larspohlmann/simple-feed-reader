import { Dialog } from '@angular/cdk/dialog';
import { Injectable, inject } from '@angular/core';
import { ExportDialogComponent } from './export-dialog.component';
import { ExportTarget } from './export-formats';

@Injectable({ providedIn: 'root' })
export class ExportDialogService {
  private readonly dialog = inject(Dialog);

  open(target: ExportTarget): void {
    this.dialog.open(ExportDialogComponent, { panelClass: 'app-dialog', data: target });
  }
}
