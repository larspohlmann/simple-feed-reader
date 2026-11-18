import { Signal } from '@angular/core';
import { takeUntilDestroyed, toObservable } from '@angular/core/rxjs-interop';
import { skip } from 'rxjs';
import { AiConfig } from './ai/ai-settings.service';

export interface ConnectionChoice {
  readonly id: number;
  readonly name: string | null;
  readonly baseUrl: string;
  readonly model: string | null;
}

export function connectionLabel(connection: ConnectionChoice): string {
  const name = connection.name ?? new URL(connection.baseUrl).host;
  return connection.model ? `${name} · ${connection.model}` : name;
}

/** The server keeps a pick that can no longer do the job, but it is no candidate: shown, never offered. */
export function unusableConnectionId(
  chosenId: number | null,
  candidates: readonly ConnectionChoice[],
): number | null {
  if (chosenId === null) return null;
  return candidates.some((candidate) => candidate.id === chosenId) ? null : chosenId;
}

/** Calls `reload` whenever the connections change after the first read: an added, edited or removed connection
 *  changes which ones can do a job. Call from an injection context. */
export function reloadOnConnectionChange(
  connections: Signal<readonly AiConfig[]>,
  reload: () => void,
): void {
  toObservable(connections).pipe(skip(1), takeUntilDestroyed()).subscribe(reload);
}
