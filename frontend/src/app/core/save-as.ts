// src/app/core/save-as.ts

/** Hands a Blob to the browser's downloader. Revokes the object URL on the
 *  next tick: Firefox and Safari queue the download asynchronously and read
 *  the blob after click() returns, so a synchronous revoke can save an empty
 *  file. */
export function saveAs(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  a.style.display = 'none';
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 0);
}
