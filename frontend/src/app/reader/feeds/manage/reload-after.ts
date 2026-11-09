import { Observable } from 'rxjs';

/** Reloads whether the write landed or not, so the store shows what the server kept. */
export function reloadAfter(write$: Observable<unknown>, reload: () => void): void {
  write$.subscribe({ next: reload, error: reload });
}
