<?php

namespace Sas\Bundle;

enum Ecosystem: string
{
    case Npm = 'npm';
    case Pypi = 'pypi';
    case Composer = 'composer';
    case Docker = 'docker';

    public function label(): string
    {
        return match ($this) {
            self::Npm => 'npm',
            self::Pypi => 'Python (PyPI)',
            self::Composer => 'Composer',
            self::Docker => 'Docker',
        };
    }
}
