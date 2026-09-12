// src/app/core/client-error-http-method.ts
//
// Angular-free (see client-error-beacon.ts): only the interceptor knows the
// request method, but the reporter builds the wire message, so the method
// travels alongside the response instance instead of through a parameter.
const methodByResponse = new WeakMap<object, string>();

export function rememberHttpMethod(response: object, method: string): void {
  methodByResponse.set(response, method);
}

export function httpMethodOf(response: object): string | undefined {
  return methodByResponse.get(response);
}
