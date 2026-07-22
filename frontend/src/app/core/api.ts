// src/app/core/api.ts
import { InjectionToken } from '@angular/core';

/** Absolute base for every backend call. '' in prod (same-origin), the Docker
 *  origin in dev. Injected so tests can override it. */
export const API_BASE_URL = new InjectionToken<string>('API_BASE_URL');
