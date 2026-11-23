import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import { SocialNetworkName } from '../../core/social-network';
import { NotificationsPageDto } from './notification.models';

@Injectable({ providedIn: 'root' })
export class NotificationsApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  page(network: SocialNetworkName, cursor: number | null): Observable<NotificationsPageDto> {
    let params = new HttpParams().set('network', network);
    if (cursor !== null) params = params.set('cursor', cursor);
    return this.http.get<NotificationsPageDto>(`${this.base}/api/me/notifications`, { params });
  }

  refresh(network: SocialNetworkName): Observable<void> {
    return this.http.post<void>(`${this.base}/api/me/notifications/refresh`, { network });
  }

  markSeen(network: SocialNetworkName, upTo: number): Observable<void> {
    return this.http.post<void>(`${this.base}/api/me/notifications/seen`, { network, upTo });
  }
}
