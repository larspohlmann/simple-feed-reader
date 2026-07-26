// src/app/discover/active-category.ts
import { Injectable, signal } from '@angular/core';

/**
 * Which category the scroll is currently inside. Rendered twice — as the desktop
 * rail's highlighted row and as the mobile chip strip's active chip — so the
 * state lives here rather than in either component.
 *
 * jumpTo() sets the active id directly AND suspends observation: a click-to-jump
 * scroll passes through every category in between, and each one would otherwise
 * report itself active on the way past.
 */
@Injectable()
export class ActiveCategory {
  private readonly id = signal<number | null>(null);
  private suspended = false;

  readonly activeId = this.id.asReadonly();

  /** An IntersectionObserver reported this section as the one in view. */
  observed(categoryId: number): void {
    if (this.suspended) return;
    this.id.set(categoryId);
  }

  /** The user clicked a rail row or a chip. */
  jumpTo(categoryId: number): void {
    this.suspended = true;
    this.id.set(categoryId);
  }

  /** The smooth scroll finished; observations count again. */
  settled(): void {
    this.suspended = false;
  }
}
