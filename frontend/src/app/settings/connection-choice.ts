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
