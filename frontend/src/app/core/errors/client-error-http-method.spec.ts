import { httpMethodOf, rememberHttpMethod } from './client-error-http-method';

describe('client-error-http-method', () => {
  it('returns the method remembered for a response instance', () => {
    const response = {};
    rememberHttpMethod(response, 'GET');

    expect(httpMethodOf(response)).toBe('GET');
  });

  it('returns undefined for a response nothing was remembered for', () => {
    expect(httpMethodOf({})).toBeUndefined();
  });
});
