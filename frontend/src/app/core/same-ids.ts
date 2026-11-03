export function sameIds<Id>(left: readonly Id[], right: readonly Id[]): boolean {
  return left.length === right.length && left.every((id, index) => id === right[index]);
}
