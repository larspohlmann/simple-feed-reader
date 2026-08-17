// src/app/core/problem.spec.ts
import { HttpErrorResponse } from '@angular/common/http';
import { REQUEST_TOO_LARGE, parseProblem } from './problem';

describe('parseProblem', () => {
  it('reads a validation_error problem+json body', () => {
    const err = new HttpErrorResponse({
      status: 422,
      error: {
        type: 'validation_error',
        title: 'Validation failed',
        status: 422,
        errors: { email: ['Not a valid address'] },
      },
    });
    const p = parseProblem(err);
    expect(p.type).toBe('validation_error');
    expect(p.errors?.['email']?.[0]).toBe('Not a valid address');
  });

  it('carries accountStatus through for account_not_active', () => {
    const err = new HttpErrorResponse({
      status: 403,
      error: {
        type: 'account_not_active',
        title: 'x',
        status: 403,
        detail: 'nope',
        accountStatus: 'suspended',
      },
    });
    expect(parseProblem(err).accountStatus).toBe('suspended');
  });

  it('falls back to a generic problem when the body is not JSON', () => {
    const err = new HttpErrorResponse({ status: 0, error: 'Network down' });
    const p = parseProblem(err);
    expect(p.status).toBe(0);
    expect(p.title.length).toBeGreaterThan(0);
  });

  // nginx refuses an oversized body itself, so what arrives is its HTML error
  // page -- never problem+json. Classifying it is the only way a feature can
  // tell "too large" apart from every other unparseable failure (#458).
  it('classifies an oversized body from the raw 413 the web server returns', () => {
    const err = new HttpErrorResponse({
      status: 413,
      error: '<html><head><title>413 Request Entity Too Large</title></head></html>',
    });
    const p = parseProblem(err);
    expect(p.type).toBe(REQUEST_TOO_LARGE);
    expect(p.status).toBe(413);
  });

  it('lets the backend keep its own type when it answers 413 with problem+json', () => {
    const err = new HttpErrorResponse({
      status: 413,
      error: { type: 'backup_too_large', title: 'Too large', status: 413 },
    });
    expect(parseProblem(err).type).toBe('backup_too_large');
  });
});
