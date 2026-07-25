// src/environments/environment.strato.ts
// Personal Strato deployment: the app is mounted under /reader on the apex
// domain, so API calls must carry that prefix. Every call site builds
// `${apiBaseUrl}/api/...`, and the bearer interceptor matches on this same
// value, so setting it here is the whole change.
export const environment = {
  production: true,
  apiBaseUrl: '/reader',
};
