import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../core/api';
import { SocialNetworkName } from '../core/social-network';

/** The surfaces a whole network can be switched out of, as their endpoints name them. */
export type NetworkExclusionSurface = 'all-items' | 'for-you' | 'trending';

/** The stored "keep this network out" switches, one endpoint family per surface. */
@Injectable({ providedIn: 'root' })
export class NetworkExclusionsApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  excludedNetworks(
    surface: NetworkExclusionSurface,
  ): Observable<{ excludedNetworks: SocialNetworkName[] }> {
    return this.http.get<{ excludedNetworks: SocialNetworkName[] }>(this.url(surface));
  }

  exclude(surface: NetworkExclusionSurface, network: SocialNetworkName): Observable<void> {
    return this.http.put<void>(`${this.url(surface)}/${network}`, null);
  }

  include(surface: NetworkExclusionSurface, network: SocialNetworkName): Observable<void> {
    return this.http.delete<void>(`${this.url(surface)}/${network}`);
  }

  private url(surface: NetworkExclusionSurface): string {
    return `${this.base}/api/${surface}/excluded-networks`;
  }
}
