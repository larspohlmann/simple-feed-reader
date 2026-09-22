import { HttpErrorResponse } from '@angular/common/http';
import { REQUEST_TOO_LARGE, parseProblem, parseProblemAsync } from './problem';

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

  it('does not mistake a Blob MIME type for a problem type', () => {
    const err = new HttpErrorResponse({
      status: 500,
      error: new Blob(['server error'], { type: 'text/html' }),
    });

    expect(parseProblem(err).type).toBe('about:blank');
  });

  it('reads a problem+json body delivered as a Blob', async () => {
    const body = new Blob([], { type: 'application/problem+json' });
    body.text = jest.fn().mockResolvedValue(
      JSON.stringify({
        type: 'backup_export_failed',
        title: 'The backup could not be created',
        status: 500,
        detail: 'The server could not read one account record.',
      }),
    );
    const err = new HttpErrorResponse({ status: 500, error: body });

    const problem = await parseProblemAsync(err);

    expect(problem.type).toBe('backup_export_failed');
    expect(problem.detail).toBe('The server could not read one account record.');
  });

  it('uses the safe fallback when a Blob does not contain JSON', async () => {
    const body = new Blob([], { type: 'text/html' });
    body.text = jest.fn().mockResolvedValue('<html>Server error</html>');
    const err = new HttpErrorResponse({ status: 500, error: body });

    await expect(parseProblemAsync(err)).resolves.toEqual({
      type: 'about:blank',
      title: 'The server answered with an unexpected reply (HTTP 500).',
      status: 500,
    });
  });

  // Strato's bot protection answers with its Apache 503 page under HTTP 200, so
  // Angular fails JSON.parse and hands the body over as { error, text } (#1112).
  it('names the web server page title when a 2xx body is an HTML page', () => {
    const err = new HttpErrorResponse({
      status: 200,
      error: {
        error: new SyntaxError('Unexpected token <'),
        text: '<html><head>\n<title>503 Service Unavailable</title>\n</head><body></body></html>',
      },
    });

    expect(parseProblem(err)).toEqual({
      type: 'about:blank',
      title:
        'The web server answered "503 Service Unavailable" instead of the app. Try again in a minute.',
      status: 200,
    });
  });

  it('names the web server page title when a non-2xx body is an HTML page', () => {
    const err = new HttpErrorResponse({
      status: 502,
      error: '<html><head><title>  502   Bad\nGateway </title></head></html>',
    });

    expect(parseProblem(err).title).toBe(
      'The web server answered "502 Bad Gateway" instead of the app. Try again in a minute.',
    );
  });

  it('names the web server page title when a Blob body is an HTML page', async () => {
    const body = new Blob([], { type: 'text/html' });
    body.text = jest
      .fn()
      .mockResolvedValue('<html><head><title>504 Gateway Time-out</title></head></html>');
    const err = new HttpErrorResponse({ status: 504, error: body });

    await expect(parseProblemAsync(err)).resolves.toEqual({
      type: 'about:blank',
      title:
        'The web server answered "504 Gateway Time-out" instead of the app. Try again in a minute.',
      status: 504,
    });
  });

  it('carries the HTTP status when a non-HTML body is not a problem document', () => {
    const err = new HttpErrorResponse({ status: 502, error: 'upstream connect error' });

    expect(parseProblem(err)).toEqual({
      type: 'about:blank',
      title: 'The server answered with an unexpected reply (HTTP 502).',
      status: 502,
    });
  });

  it('carries the HTTP status when an HTML body has no title', () => {
    const err = new HttpErrorResponse({
      status: 200,
      error: { text: '<html><body>x</body></html>' },
    });

    expect(parseProblem(err).title).toBe(
      'The server answered with an unexpected reply (HTTP 200).',
    );
  });

  it('keeps "Could not reach the server" for a dropped connection', () => {
    const err = new HttpErrorResponse({ status: 0, error: '<html><title>x</title></html>' });

    expect(parseProblem(err).title).toBe('Could not reach the server');
  });
});
