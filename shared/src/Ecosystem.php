<?php

namespace Sas\Bundle;

enum Ecosystem: string
{
    case Npm = 'npm';
    case Pypi = 'pypi';
    case Composer = 'composer';
    case Docker = 'docker';
    case Deb = 'deb';
    case Rpm = 'rpm';
    case Galaxy = 'galaxy';
    case Terraform = 'terraform';

    public function label(): string
    {
        return match ($this) {
            self::Npm => 'npm',
            self::Pypi => 'Python (PyPI)',
            self::Composer => 'Composer',
            self::Docker => 'Docker',
            self::Deb => 'Debian (apt)',
            self::Rpm => 'RPM (AlmaLinux, CentOS)',
            self::Galaxy => 'Ansible Galaxy',
            self::Terraform => 'Terraform',
        };
    }
}
