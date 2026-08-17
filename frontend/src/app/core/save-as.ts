// src/app/core/save-as.ts

const CONTENT_DISPOSITION_FILENAME = /filename="?([^";]+)"?/i;

/** Pulls the filename the server chose out of a Content-Disposition header
 *  ("attachment; filename=\"...\""). Falls back to a static name when the
 *  header is missing or carries none -- an intermediary that strips it, or a
 *  response that never set one -- so the download never ends up unnamed. */
export function filenameFromContentDisposition(header: string | null, fallback: string): string {
  const match = header?.match(CONTENT_DISPOSITION_FILENAME);
  return match ? match[1] : fallback;
}

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
