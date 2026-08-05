// src/app/core/boot-error-surface.ts
/**
 * Reveals the static `#boot-error` div declared in index.html (#280).
 *
 * Two unrelated failures land here: `bootstrapApplication` itself rejecting
 * (main.ts), and a lazy route chunk failing after the platform is already up
 * (app.config.ts's navigation error handler). Both leave the router outlet
 * permanently empty with nothing on screen to act on, so both reveal the same
 * surface the same way.
 *
 * This must stay a plain function with no Angular imports: the bootstrap-reject
 * caller runs before any injector exists, so nothing here can depend on DI, a
 * service, or anything the bundle would still need to resolve.
 */
export function revealBootErrorSurface(error: unknown): void {
  // A console line helps nobody on a phone, but it is the only trace once the
  // surface takes over; keep the failure diagnosable instead of swallowing it.
  console.error(error);
  document.getElementById('boot-error')?.removeAttribute('hidden');
}
