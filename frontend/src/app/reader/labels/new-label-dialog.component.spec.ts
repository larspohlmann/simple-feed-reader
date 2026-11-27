import { TestBed } from '@angular/core/testing';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { Subject, of } from 'rxjs';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { Problem } from '../../core/problem';
import { ToastService } from '../../shared/toast/toast.service';
import { LabelledRow } from './labelled-row';
import { NewLabelDialogComponent } from './new-label-dialog.component';

describe('NewLabelDialogComponent', () => {
  let close: jest.Mock;
  let createLabel: jest.Mock;
  let showProblem: jest.Mock;

  function mount() {
    close = jest.fn();
    showProblem = jest.fn();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        { provide: DialogRef, useValue: { close } },
        {
          provide: DIALOG_DATA,
          useValue: { row: { createLabel } as unknown as LabelledRow },
        },
        { provide: ToastService, useValue: { showProblem } },
      ],
    });
    const fixture = TestBed.createComponent(NewLabelDialogComponent);
    fixture.detectChanges();
    return fixture;
  }

  function submit(fixture: ReturnType<typeof mount>, name: string): void {
    const field: HTMLInputElement = fixture.nativeElement.querySelector('#new-label-name');
    field.value = name;
    field.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    fixture.nativeElement.querySelector('button[type="submit"]').click();
    fixture.detectChanges();
  }

  it('creates the trimmed label on the entry and closes', () => {
    createLabel = jest.fn(() => of(undefined));
    const fixture = mount();

    submit(fixture, ' Work ');

    expect(createLabel).toHaveBeenCalledWith('Work');
    expect(close).toHaveBeenCalled();
  });

  it('stays open behind a toast when the name is taken', () => {
    const answer = new Subject<void>();
    createLabel = jest.fn(() => answer);
    const fixture = mount();
    const taken: Problem = { type: 'label_name_taken', title: 'Taken', status: 409 };

    submit(fixture, 'Work');
    answer.error(taken);
    fixture.detectChanges();

    expect(showProblem).toHaveBeenCalledWith(taken);
    expect(close).not.toHaveBeenCalled();
    expect(fixture.nativeElement.querySelector('button[type="submit"]').disabled).toBe(false);
  });

  it('offers no submit for a blank name', () => {
    createLabel = jest.fn(() => of(undefined));
    const fixture = mount();

    submit(fixture, '   ');

    expect(createLabel).not.toHaveBeenCalled();
  });
});
