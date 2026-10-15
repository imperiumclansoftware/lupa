<?php

namespace App\Resolver;

/** État de la résolution : paquets retenus, en attente, dépendances introuvables. */
final class RpmState
{
    /** @var array<string, int> */
    public array $selected = [];
    /** @var list<int> */
    public array $queue = [];
    /** @var array<string, int> */
    public array $pending = [];
    /** @var array<string, string> */
    public array $missing = [];
}
