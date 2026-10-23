import { Injectable, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { TranslocoService } from '@jsverse/transloco';

/** translate() is one-shot: a computed that calls it reads `loaded` to re-run once a switched language's dictionary is in. */
@Injectable({ providedIn: 'root' })
export class ActiveDictionary {
  readonly loaded = toSignal(inject(TranslocoService).selectTranslation());
}
