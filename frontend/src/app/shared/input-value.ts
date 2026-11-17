export function inputValue(event: Event): string {
  return (event.target as HTMLInputElement).value;
}

export function inputChecked(event: Event): boolean {
  return (event.target as HTMLInputElement).checked;
}
