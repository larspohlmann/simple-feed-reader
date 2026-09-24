import { Injectable, computed, inject } from '@angular/core';
import { UserDeviceStorage } from '../core/user-device-storage';
import { ListOrder } from './models';
import { Selection, listOrderKey } from './query';

const NAME = 'oldest-first-views';

@Injectable({ providedIn: 'root' })
export class ListOrderService {
  private readonly storage = inject(UserDeviceStorage);

  readonly oldestFirstViews = computed(() => parseViewKeys(this.storage.read(NAME)), {
    equal: sameViewKeys,
  });

  orderFor(selection: Selection): ListOrder {
    const key = listOrderKey(selection);
    return key !== null && this.oldestFirstViews().has(key) ? 'oldest' : 'newest';
  }

  set(selection: Selection, order: ListOrder): void {
    const key = listOrderKey(selection);
    if (key === null) return;
    const views = new Set(this.oldestFirstViews());
    if (order === 'oldest') views.add(key);
    else views.delete(key);
    this.storage.write(NAME, views.size === 0 ? null : JSON.stringify([...views].sort()));
  }
}

function parseViewKeys(stored: string | null): ReadonlySet<string> {
  const parsed = parsedJson(stored);
  if (!Array.isArray(parsed)) return new Set();
  return new Set(parsed.filter((key): key is string => typeof key === 'string'));
}

function parsedJson(stored: string | null): unknown {
  if (stored === null) return null;
  try {
    return JSON.parse(stored) as unknown;
  } catch {
    return null;
  }
}

function sameViewKeys(a: ReadonlySet<string>, b: ReadonlySet<string>): boolean {
  return a.size === b.size && [...a].every((key) => b.has(key));
}
