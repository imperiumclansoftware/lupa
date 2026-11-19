<?php

namespace Sas\Bundle\Doctrine;

use Doctrine\DBAL\ParameterType;
use Symfony\Bridge\Doctrine\Types\AbstractUidType;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Type « uuid » stocké en BLOB de 16 octets sous SQLite. Celui de Symfony lie la valeur comme une
 * chaîne : SQLite la range alors en TEXT, et la même valeur écrite en BLOB (migration, requête SQL
 * directe) ne lui serait plus égale. Celui-ci lie toujours en binaire (Rémus et Romulus n'utilisent
 * que SQLite).
 */
final class BinaryUuidType extends AbstractUidType
{
    public function getName(): string
    {
        return UuidType::NAME;
    }

    public function getBindingType(): ParameterType
    {
        return ParameterType::BINARY;
    }

    protected function getUidClass(): string
    {
        return Uuid::class;
    }
}
