/** `JSON.stringify` with every `/` written as `\/`; the parsed value is unchanged. */
export function stringifyWithEscapedSlashes(value: unknown): string {
  return JSON.stringify(value).replaceAll('/', '\\/');
}
