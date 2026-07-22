// src/app/auth/altcha.service.ts
import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../core/api';
import { AltchaChallenge } from './altcha';

@Injectable({ providedIn: 'root' })
export class AltchaService {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  challenge(): Observable<AltchaChallenge> {
    return this.http.get<AltchaChallenge>(`${this.base}/api/auth/altcha-challenge`);
  }
}
