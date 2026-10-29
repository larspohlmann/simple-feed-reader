const URI_LIST = 'text/uri-list';

export function carriesUrl(transfer: DataTransfer | null): boolean {
  return transfer?.types.includes(URI_LIST) ?? false;
}

/** The first web address a drop carries: `text/uri-list` lines (`#` starts a
 *  comment), else a plain-text payload that is itself one address. */
export function droppedUrl(transfer: DataTransfer): string | null {
  const listed = transfer
    .getData(URI_LIST)
    .split(/\r?\n/)
    .map((line) => line.trim())
    .find((line) => !line.startsWith('#') && isWebAddress(line));
  if (listed) return listed;

  const text = transfer.getData('text/plain').trim();
  return isWebAddress(text) ? text : null;
}

function isWebAddress(candidate: string): boolean {
  if (!URL.canParse(candidate)) return false;
  const { protocol } = new URL(candidate);
  return protocol === 'http:' || protocol === 'https:';
}
