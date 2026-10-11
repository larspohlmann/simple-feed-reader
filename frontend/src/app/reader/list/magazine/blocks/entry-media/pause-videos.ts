export function pauseVideos(root: ParentNode): void {
  for (const video of Array.from(root.querySelectorAll('video'))) video.pause();
}
