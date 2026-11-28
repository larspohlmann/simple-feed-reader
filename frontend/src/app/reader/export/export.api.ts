import { HttpClient, HttpParams, HttpResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import { ListOrder } from '../models';
import { ExportFormat, ExportTarget } from './export-formats';

@Injectable({ providedIn: 'root' })
export class ExportApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  download(
    format: ExportFormat,
    target: ExportTarget,
    order: ListOrder,
  ): Observable<HttpResponse<Blob>> {
    let params = new HttpParams().set('view', target.collection);
    if (target.label !== null) params = params.set('label', String(target.label));
    if (order === 'oldest') params = params.set('order', 'asc');

    return this.http.get(`${this.base}${format.path}`, {
      params,
      responseType: 'blob',
      observe: 'response',
    });
  }
}
