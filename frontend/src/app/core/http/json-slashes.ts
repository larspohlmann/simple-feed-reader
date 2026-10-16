/** Writes every `/` of serialised JSON as `\/`; the parsed value is unchanged. */
export function escapeJsonSlashes(json: string): string {
  return json.replaceAll('/', '\\/');
}
