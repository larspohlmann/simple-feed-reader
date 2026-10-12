export function inputValue(event: Event): string {
  return (event.target as HTMLInputElement).value;
}
