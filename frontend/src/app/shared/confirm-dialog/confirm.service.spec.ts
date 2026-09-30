import { TestBed } from '@angular/core/testing';
import { Dialog } from '@angular/cdk/dialog';
import { of } from 'rxjs';
import { ConfirmDialogComponent } from './confirm-dialog.component';
import { ConfirmService } from './confirm.service';

describe('ConfirmService', () => {
  const data = { title: 'Delete?', message: 'Gone for good.', confirmLabel: 'Delete' };

  function serviceClosingWith(result: boolean | undefined): {
    service: ConfirmService;
    open: jest.Mock;
  } {
    const open = jest.fn(() => ({ closed: of(result) }));
    TestBed.configureTestingModule({ providers: [{ provide: Dialog, useValue: { open } }] });
    return { service: TestBed.inject(ConfirmService), open };
  }

  it('opens the confirm dialog as an alert dialog', () => {
    const { service, open } = serviceClosingWith(true);
    service.confirmThen(data, () => undefined);
    expect(open).toHaveBeenCalledWith(ConfirmDialogComponent, {
      data,
      role: 'alertdialog',
      panelClass: 'app-dialog',
    });
  });

  it('runs the action only on confirm', () => {
    const action = jest.fn();
    serviceClosingWith(true).service.confirmThen(data, action);
    expect(action).toHaveBeenCalledTimes(1);
  });

  it.each([false, undefined])('skips the action when the dialog closes with %s', (result) => {
    const action = jest.fn();
    serviceClosingWith(result).service.confirmThen(data, action);
    expect(action).not.toHaveBeenCalled();
  });

  it('ask() maps a dismissed dialog to false', (done) => {
    serviceClosingWith(undefined)
      .service.ask(data)
      .subscribe((confirmed) => {
        expect(confirmed).toBe(false);
        done();
      });
  });
});
