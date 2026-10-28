import { Injectable, inject } from '@angular/core';
import { UserDeviceStorage } from '../../core/preferences/user-device-storage';

export interface SavedPosition {
  url: string;
  seconds: number;
  durationSeconds: number;
}

export const KEPT_POSITIONS = 100;

const NAME = 'listen-positions';

/** Where each long track was left, per account on this device, newest first (#1866). */
@Injectable({ providedIn: 'root' })
export class ListenPositions {
  private readonly storage = inject(UserDeviceStorage);

  get(url: string): SavedPosition | null {
    return this.all().find((saved) => saved.url === url) ?? null;
  }

  remember(position: SavedPosition): void {
    this.write([position, ...this.without(position.url)].slice(0, KEPT_POSITIONS));
  }

  forget(url: string): void {
    if (this.get(url)) this.write(this.without(url));
  }

  private without(url: string): SavedPosition[] {
    return this.all().filter((saved) => saved.url !== url);
  }

  private all(): SavedPosition[] {
    try {
      const stored: unknown = JSON.parse(this.storage.read(NAME) ?? '[]');
      return Array.isArray(stored) ? stored.filter(isSavedPosition) : [];
    } catch {
      return [];
    }
  }

  private write(positions: SavedPosition[]): void {
    this.storage.write(NAME, JSON.stringify(positions));
  }
}

function isSavedPosition(value: unknown): value is SavedPosition {
  const saved = value as Partial<SavedPosition> | null;
  return (
    typeof saved?.url === 'string' &&
    typeof saved.seconds === 'number' &&
    typeof saved.durationSeconds === 'number'
  );
}
