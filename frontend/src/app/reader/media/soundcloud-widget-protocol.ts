export const SOUNDCLOUD_WIDGET_ORIGIN = 'https://w.soundcloud.com';
export const SOUNDCLOUD_PLAYER_URL = `${SOUNDCLOUD_WIDGET_ORIGIN}/player/`;
export const MILLISECONDS_PER_SECOND = 1000;

export interface WidgetMessage {
  readonly method: string;
  readonly value?: unknown;
}

export function widgetMessage(method: string, value?: unknown): WidgetMessage {
  return value === undefined ? { method } : { method, value };
}

export function seekToMessage(seconds: number): WidgetMessage {
  return widgetMessage('seekTo', Math.round(seconds * MILLISECONDS_PER_SECOND));
}

/** Asks the widget for each of `events`, then for the sound's length. */
export function subscriptionMessages(events: readonly string[]): WidgetMessage[] {
  return [
    ...events.map((event) => widgetMessage('addEventListener', event)),
    widgetMessage('getDuration'),
  ];
}

export function widgetMessageOf(data: unknown): WidgetMessage | null {
  if (typeof data !== 'string') return null;
  try {
    const parsed: unknown = JSON.parse(data);
    return typeof widgetFieldOf(parsed, 'method') === 'string' ? (parsed as WidgetMessage) : null;
  } catch {
    return null;
  }
}

export function widgetFieldOf(value: unknown, name: string): unknown {
  if (typeof value !== 'object' || value === null) return undefined;
  return (value as Record<string, unknown>)[name];
}

export function postToWidgetFrame(frame: HTMLIFrameElement, message: WidgetMessage): void {
  frame.contentWindow?.postMessage(JSON.stringify(message), SOUNDCLOUD_WIDGET_ORIGIN);
}
