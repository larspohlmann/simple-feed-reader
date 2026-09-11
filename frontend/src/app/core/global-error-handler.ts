// src/app/core/global-error-handler.ts
import { ErrorHandler, Injectable, inject } from '@angular/core';
import { ClientErrorReporter } from './client-error-reporter';

/**
 * Angular's default ErrorHandler only logs. This one keeps that console output
 * (developers and the boot-error trace still need it) and additionally reports
 * the error to Loki via the backend (#984).
 */
@Injectable()
export class ReportingErrorHandler implements ErrorHandler {
  private readonly reporter = inject(ClientErrorReporter);

  handleError(error: unknown): void {
    console.error(error);
    this.reporter.report(error);
  }
}
