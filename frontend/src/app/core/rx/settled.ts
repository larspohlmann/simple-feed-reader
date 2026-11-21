import { Observable, Observer, Subject, finalize } from 'rxjs';

/** Subscribes `observer` to `source$` and answers a stream that completes once `source$` settles, failed or not. */
export function subscribeUntilSettled<T>(
  source$: Observable<T>,
  observer: Partial<Observer<T>>,
): Observable<void> {
  const settled = new Subject<void>();
  source$.pipe(finalize(() => settled.complete())).subscribe(observer);
  return settled.asObservable();
}
