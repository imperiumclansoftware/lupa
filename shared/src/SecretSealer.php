<?php

namespace Sas\Bundle;

/** Chiffrement des secrets conservés en base (identifiants des dépôts, clé secrète S3). */
interface SecretSealer
{
    public function seal(string $plain): string;

    public function open(string $sealed): string;
}
