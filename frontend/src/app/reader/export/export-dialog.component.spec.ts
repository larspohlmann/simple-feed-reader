import { HttpErrorResponse, HttpHeaders, HttpResponse } from '@angular/common/http';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { TestBed } from '@angular/core/testing';
import { Subject } from 'rxjs';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { saveAs } from '../../core/download/save-as';
import { ListOrderService } from '../list/list-order.service';
import { EXPORT_FORMATS, ExportTarget } from './export-formats';
import { ExportDialogComponent } from './export-dialog.component';
import { ExportApi } from './export.api';

jest.mock('../../core/download/save-as', () => ({
  ...jest.requireActual('../../core/download/save-as'),
  saveAs: jest.fn(),
}));

describe('ExportDialogComponent', () => {
  const target: ExportTarget = { collection: 'kept', label: 12, title: 'Kept – Reading list' };
  let close: jest.Mock;
  let download: jest.Mock;
  let orderFor: jest.Mock;

  function mount() {
    close = jest.fn();
    orderFor = jest.fn(() => 'oldest');
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        { provide: DialogRef, useValue: { close } },
        { provide: DIALOG_DATA, useValue: target },
        { provide: ExportApi, useValue: { download } },
        { provide: ListOrderService, useValue: { orderFor } },
      ],
    });
    const fixture = TestBed.createComponent(ExportDialogComponent);
    fixture.detectChanges();
    return fixture;
  }

  function radios(fixture: ReturnType<typeof mount>): HTMLInputElement[] {
    return [...fixture.nativeElement.querySelectorAll('input[type="radio"]')];
  }

  function submit(fixture: ReturnType<typeof mount>): void {
    fixture.nativeElement.querySelector('button[type="submit"]').click();
    fixture.detectChanges();
  }

  beforeEach(() => jest.mocked(saveAs).mockClear());

  it('renders one radio per format with its name and description, the first checked', () => {
    download = jest.fn();
    const fixture = mount();

    const text: string = fixture.nativeElement.textContent;
    expect(radios(fixture)).toHaveLength(EXPORT_FORMATS.length);
    expect(radios(fixture).map((radio) => radio.checked)).toEqual([true, false]);
    expect(text).toContain('Bookmarks (HTML)');
    expect(text).toContain('Raindrop, Linkding, Wallabag or Pinboard');
    expect(text).toContain('Google Reader JSON');
    expect(text).toContain('FreshRSS subscribes');
  });

  it('names the target in the heading', () => {
    download = jest.fn();
    const fixture = mount();

    expect(fixture.nativeElement.querySelector('h2').textContent).toContain(
      'Export Kept – Reading list',
    );
  });

  it('downloads the chosen format in the list order, saves the named file and closes', () => {
    const answer = new Subject<HttpResponse<Blob>>();
    download = jest.fn(() => answer);
    const fixture = mount();
    const blob = new Blob(['{}']);

    radios(fixture)[1].click();
    fixture.detectChanges();
    submit(fixture);
    answer.next(
      new HttpResponse({
        body: blob,
        headers: new HttpHeaders({
          'Content-Disposition': 'attachment; filename="kept-starred.json"',
        }),
      }),
    );

    expect(orderFor).toHaveBeenCalledWith({ kind: 'kept', label: 12 });
    expect(download).toHaveBeenCalledWith(EXPORT_FORMATS[1], target, 'oldest');
    expect(saveAs).toHaveBeenCalledWith(blob, 'kept-starred.json');
    expect(close).toHaveBeenCalled();
  });

  it('falls back to the format file name without a Content-Disposition', () => {
    const answer = new Subject<HttpResponse<Blob>>();
    download = jest.fn(() => answer);
    const fixture = mount();

    submit(fixture);
    answer.next(new HttpResponse({ body: new Blob(['x']) }));

    expect(jest.mocked(saveAs).mock.calls[0][1]).toBe('bookmarks.html');
  });

  it('disables submit and refuses a second download while one runs', () => {
    download = jest.fn(() => new Subject<HttpResponse<Blob>>());
    const fixture = mount();

    submit(fixture);
    submit(fixture);

    expect(fixture.nativeElement.querySelector('button[type="submit"]').disabled).toBe(true);
    expect(download).toHaveBeenCalledTimes(1);
  });

  it('saves nothing when the dialog is destroyed mid-download', () => {
    const answer = new Subject<HttpResponse<Blob>>();
    download = jest.fn(() => answer);
    const fixture = mount();

    submit(fixture);
    fixture.destroy();
    answer.next(new HttpResponse({ body: new Blob(['x']) }));

    expect(saveAs).not.toHaveBeenCalled();
  });

  it('shows the detail of a problem blob and stays open', async () => {
    const answer = new Subject<HttpResponse<Blob>>();
    download = jest.fn(() => answer);
    const fixture = mount();
    const problem = new Blob([], { type: 'application/problem+json' });
    problem.text = jest.fn().mockResolvedValue(
      JSON.stringify({
        type: 'not_found',
        title: 'Not found',
        status: 404,
        detail: 'No such label.',
      }),
    );

    submit(fixture);
    answer.error(new HttpErrorResponse({ status: 404, error: problem }));
    await new Promise((resolve) => setTimeout(resolve));
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain('No such label.');
    expect(fixture.nativeElement.textContent).not.toContain('[object Blob]');
    expect(close).not.toHaveBeenCalled();
    expect(fixture.nativeElement.querySelector('button[type="submit"]').disabled).toBe(false);
  });
});
