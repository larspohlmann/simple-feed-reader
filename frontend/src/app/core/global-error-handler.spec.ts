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

  // provideBrowserGlobalErrorListeners() (app.config.ts) forwards a window
  // `unhandledrejection` event's `reason` straight into this same
  // handleError() — there is no separate listener for it (#984). A rejection
  // with a non-Error reason exercises that path without simulating the
  // window event, which would test Angular's own internals instead of ours.
  it('reports an unhandled-rejection-style reason the same way', () => {
    const report = jest.fn();
    jest.spyOn(console, 'error').mockImplementation(() => undefined);
    TestBed.configureTestingModule({
      providers: [ReportingErrorHandler, { provide: ClientErrorReporter, useValue: { report } }],
    });

    TestBed.inject(ReportingErrorHandler).handleError('not ready');

    expect(report).toHaveBeenCalledWith('not ready');
  });
});
