export const SOUNDCLOUD_WIDGET_ORIGIN = 'https://w.soundcloud.com';

export interface WidgetMessage {
  readonly method: string;
  readonly value?: unknown;
}

export function widgetMessage(method: string, value?: unknown): WidgetMessage {
  return value === undefined ? { method } : { method, value };
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
