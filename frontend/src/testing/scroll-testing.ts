/** Make an element's scrollTop a plain settable value, since jsdom does no layout. */
export function trackScrollTop(element: HTMLElement): { top: number } {
  const state = { top: 0 };
  Object.defineProperty(element, 'scrollTop', {
    configurable: true,
    get: () => state.top,
    set: (value: number) => {
      state.top = value;
    },
  });
  return state;
}
