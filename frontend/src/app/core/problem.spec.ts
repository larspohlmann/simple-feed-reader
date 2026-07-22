// src/app/core/problem.spec.ts
import { HttpErrorResponse } from '@angular/common/http';
import { parseProblem } from './problem';

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
});
