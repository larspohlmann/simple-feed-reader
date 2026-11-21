import { AiRunKind } from '../settings.models';

/** Ids restart per kind, so a run is told apart by kind and id together. */
export function runKey(run: { kind: AiRunKind; id: number }): string {
  return `${run.kind}:${run.id}`;
}
