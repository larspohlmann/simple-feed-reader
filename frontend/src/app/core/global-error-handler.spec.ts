import { TestBed } from '@angular/core/testing';
import { ClientErrorReporter } from './client-error-reporter';
import { ReportingErrorHandler } from './global-error-handler';

describe('ReportingErrorHandler', () => {
  it('logs to the console and reports the error', () => {
    const report = jest.fn();
    const consoleError = jest.spyOn(console, 'error').mockImplementation(() => undefined);
    TestBed.configureTestingModule({
      providers: [ReportingErrorHandler, { provide: ClientErrorReporter, useValue: { report } }],
    });

    const error = new Error('boom');
    TestBed.inject(ReportingErrorHandler).handleError(error);

    expect(consoleError).toHaveBeenCalledWith(error);
    expect(report).toHaveBeenCalledWith(error);
  });
});
