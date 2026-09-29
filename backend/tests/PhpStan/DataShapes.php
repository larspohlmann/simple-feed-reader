<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

/**
 * A model is domain data: final readonly or an enum, holding no service and naming no DTO. A DTO is a transfer
 * shape: final readonly, holding no service, never named like a model (#1202).
 */
final readonly class DataShapes implements ServiceRoleChecker
{
    public function violationsIn(ServiceRoleMap $map): array
    {
        $violations = [];
        foreach ($map->applicationClasses() as $class) {
            if (!$class->isPlainClass()) {
                continue;
            }
            $found = match ($class->role()) {
                ServiceRoleNames::MODEL => self::modelViolations($map, $class),
                ServiceRoleNames::DTO => $class->shape->isStaticOnly() ? [] : self::dtoViolations($map, $class),
                default => [],
            };
            $violations = [...$violations, ...$found];
        }

        return $violations;
    }

    /** @return list<ServiceRoleViolation> */
    private static function modelViolations(ServiceRoleMap $map, ServiceRoleClass $model): array
    {
        $violations = self::dataViolations($map, $model, ServiceRoleCheck::ModelShape);
        foreach ($model->dtoReferences as $dto) {
            $violations[] = new ServiceRoleViolation(
                ServiceRoleCheck::ModelShape,
                $model,
                sprintf('names the DTO %s; map it to a model at the boundary', $dto),
            );
        }

        return $violations;
    }

    /** @return list<ServiceRoleViolation> */
    private static function dtoViolations(ServiceRoleMap $map, ServiceRoleClass $dto): array
    {
        $violations = self::dataViolations($map, $dto, ServiceRoleCheck::DtoShape);
        if (str_ends_with($dto->shortName(), ServiceRoleNames::MODEL)) {
            $violations[] = new ServiceRoleViolation(ServiceRoleCheck::DtoShape, $dto, 'is a DTO named like a model');
        }

        return $violations;
    }

    /** @return list<ServiceRoleViolation> */
    private static function dataViolations(
        ServiceRoleMap $map,
        ServiceRoleClass $dataClass,
        ServiceRoleCheck $check,
    ): array {
        $violations = [];
        if (!$dataClass->shape->isFinalReadonly()) {
            $violations[] = new ServiceRoleViolation($check, $dataClass, 'is data, so it is final readonly');
        }
        foreach (SuppliedConstructorTypes::of($dataClass->reflection) as $type) {
            if ($map->isCollaborator($type)) {
                $violations[] = new ServiceRoleViolation(
                    $check,
                    $dataClass,
                    sprintf('is data but takes the service %s', $type),
                );
            }
        }

        return $violations;
    }
}
