export const YOUTUBE_PLAYER_ORIGIN = 'https://www.youtube-nocookie.com';

const WIDGET = { id: 1, channel: 'widget' };

export const LISTENING = { event: 'listening', ...WIDGET };

export type PlayerMessage = Record<string, unknown>;

export function playerCommand(name: string, commandArguments: unknown[]): object {
  return { event: 'command', func: name, args: commandArguments, ...WIDGET };
}

export function postToPlayerFrame(frame: HTMLIFrameElement, message: object): void {
  frame.contentWindow?.postMessage(JSON.stringify(message), YOUTUBE_PLAYER_ORIGIN);
}

export function playerMessageOf(data: unknown): PlayerMessage | null {
  if (typeof data !== 'string') return null;
  try {
    return recordOf(JSON.parse(data));
  } catch {
    return null;
  }
}

/** The `info` a delivery carries, or nothing to read. */
export function playerInfoOf(message: PlayerMessage | null): PlayerMessage {
  return recordOf(message?.['info']) ?? {};
}

export function finiteNumberOf(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

export function recordOf(value: unknown): PlayerMessage | null {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) return null;
  return value as PlayerMessage;
}
