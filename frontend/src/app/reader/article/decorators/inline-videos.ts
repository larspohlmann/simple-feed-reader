/** iOS Safari plays a video fullscreen unless it carries `playsinline`, which
 *  Angular's sanitizer strips from the bound body, so it is set back here. */
export function playVideosInline(host: HTMLElement): void {
  for (const video of Array.from(host.querySelectorAll('video'))) {
    video.playsInline = true;
  }
}
