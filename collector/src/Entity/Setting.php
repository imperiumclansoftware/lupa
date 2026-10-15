<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Réglage clé / valeur (révision de la liste noire…). */
#[ORM\Entity]
class Setting
{
    #[ORM\Id, ORM\Column(length: 64)]
    private string $name;

    #[ORM\Column(type: Types::TEXT)]
    private string $value;

    public function __construct(string $name, string $value)
    {
        $this->name = $name;
        $this->value = $value;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): void
    {
        $this->value = $value;
    }
}
