export const sameMembers = <Member>(
  left: ReadonlySet<Member>,
  right: ReadonlySet<Member>,
): boolean => left.size === right.size && [...left].every((member) => right.has(member));
