import { HttpErrorResponse } from '@angular/common/http';
import { TimeoutError } from 'rxjs';
import { describeLoadError } from './reader-load-error';

describe('describeLoadError', () => {
  it('renders the complete HTTP message: status, status text and request URL', () => {
    const error = new HttpErrorResponse({
      status: 502,
      statusText: 'Bad Gateway',
      url: 'https://host.test/api/entries/1/reader',
    });
    const detail = describeLoadError(error);
    expect(detail).toContain('502');
    expect(detail).toContain('Bad Gateway');
    expect(detail).toContain('https://host.test/api/entries/1/reader');
  });

  it('appends a problem+json detail from the response body', () => {
    const error = new HttpErrorResponse({
      status: 500,
      statusText: 'Internal Server Error',
      url: 'https://host.test/api/entries/1/reader',
      error: { title: 'Extraction failed', detail: 'upstream timed out fetching the page' },
    });
    expect(describeLoadError(error)).toContain('upstream timed out fetching the page');
  });

  it('appends a plain-text error body verbatim', () => {
    const error = new HttpErrorResponse({
      status: 503,
      statusText: 'Service Unavailable',
      error: 'gateway is warming up',
    });
    expect(describeLoadError(error)).toContain('gateway is warming up');
  });

  it('reports the load timeout in words', () => {
    expect(describeLoadError(new TimeoutError())).toContain('Timeout');
  });

  it('falls back to a plain error message for a non-HTTP failure', () => {
    expect(describeLoadError(new Error('boom'))).toBe('boom');
  });

  it('never returns an empty string, even for an inscrutable value', () => {
    expect(describeLoadError(null)).not.toBe('');
    expect(describeLoadError(undefined)).not.toBe('');
  });
});
