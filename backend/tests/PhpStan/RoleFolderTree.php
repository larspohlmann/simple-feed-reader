<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

/** One Factory/ or Model/ folder and everything below it. */
final readonly class RoleFolderTree
{
    /** @var list<ServiceRoleClass> */
    private array $interfaces;

    /** @param list<ServiceRoleClass> $members */
    public function __construct(public string $root, public string $role, public array $members)
    {
        $this->interfaces = array_values(array_filter(
            $members,
            static fn (ServiceRoleClass $member): bool => $member->isInterface(),
        ));
    }

    /** @return non-empty-list<string> the folders the member may sit in */
    public function foldersFor(ServiceRoleClass $member): array
    {
        if ($this->isFlat()) {
            return [$this->root];
        }
        $folders = [];
        foreach ($this->interfaces as $interface) {
            if ($interface === $member || \in_array($interface->name(), $member->interfaceNames(), true)) {
                $folders[] = $this->folderOf($interface);
            }
        }

        return [] === $folders ? [$this->root] : $folders;
    }

    private function isFlat(): bool
    {
        if (1 !== \count($this->interfaces)) {
            return false;
        }
        foreach ($this->members as $member) {
            if (!$member->isInterface() && !\in_array($this->interfaces[0]->name(), $member->interfaceNames(), true)) {
                return false;
            }
        }

        return true;
    }

    private function folderOf(ServiceRoleClass $interface): string
    {
        $base = ServiceRoleNames::withoutSuffix($interface->shortName(), $this->role . 'Interface');

        return $this->root . '\\' . ServiceRoleNames::withoutSuffix($base, 'Interface');
    }
}
