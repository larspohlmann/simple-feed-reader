import { ProxyOutcome } from '../app/shared/proxied-image/image-proxy.service';

/** A proxy whose retry never settles, so a surface that waits on it never hides its image. */
export function neverRecoveringImageProxy() {
  const attempts: HTMLImageElement[] = [];
  return {
    attempts,
    recover: (image: HTMLImageElement): Promise<ProxyOutcome> => {
      attempts.push(image);
      return new Promise<ProxyOutcome>(() => undefined);
    },
  };
}
