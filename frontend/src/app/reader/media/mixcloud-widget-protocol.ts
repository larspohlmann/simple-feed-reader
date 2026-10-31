export const MIXCLOUD_WIDGET_ORIGIN = 'https://player-widget.mixcloud.com';
export const MIXCLOUD_PLAYER_URL = `${MIXCLOUD_WIDGET_ORIGIN}/widget/iframe/`;

const PLAYER_WIDGET = 'playerWidget';

export interface MixcloudMessage {
  readonly type: string;
  readonly data: unknown;
}

export interface MixcloudEvent {
  readonly eventName: string;
  readonly args: readonly unknown[];
}

export interface MixcloudMethodCall {
  readonly type: 'method';
  readonly data: {
    readonly methodId: number;
    readonly methodName: string;
    readonly args: readonly unknown[];
  };
}

export const GET_API = { type: 'getApi' } as const;

let lastMethodId = 0;

export function methodCall(
  methodName: string,
  methodArguments: readonly unknown[] = [],
): MixcloudMethodCall {
  lastMethodId += 1;
  return { type: 'method', data: { methodId: lastMethodId, methodName, args: methodArguments } };
}

/** A message the player widget posts; ours carry no `mixcloud` key, so they never read as one. */
export function mixcloudMessageOf(data: unknown): MixcloudMessage | null {
  const message = parsedRecordOf(data);
  const type = message?.['type'];
  if (message?.['mixcloud'] !== PLAYER_WIDGET || typeof type !== 'string') return null;
  return { type, data: message['data'] };
}

export function mixcloudEventOf(message: MixcloudMessage): MixcloudEvent | null {
  if (message.type !== 'event') return null;
  const event = recordOf(message.data);
  const eventName = event?.['eventName'];
  const eventArguments: unknown = event?.['args'] ?? [];
  if (typeof eventName !== 'string' || !Array.isArray(eventArguments)) return null;
  return { eventName, args: eventArguments as unknown[] };
}

export function postToMixcloudFrame(frame: HTMLIFrameElement, message: object): void {
  frame.contentWindow?.postMessage(JSON.stringify(message), MIXCLOUD_WIDGET_ORIGIN);
}

function parsedRecordOf(data: unknown): Record<string, unknown> | null {
  if (typeof data !== 'string') return null;
  try {
    return recordOf(JSON.parse(data));
  } catch {
    return null;
  }
}

function recordOf(value: unknown): Record<string, unknown> | null {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) return null;
  return value as Record<string, unknown>;
}
