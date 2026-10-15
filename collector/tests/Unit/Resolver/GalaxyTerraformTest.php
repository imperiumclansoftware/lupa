<?php

namespace App\Tests\Unit\Resolver;

use App\Resolver\Constraint;
use App\Resolver\GalaxyResolver;
use App\Resolver\TerraformResolver;
use PHPUnit\Framework\TestCase;
use Sas\Bundle\Ecosystem;
use Sas\Bundle\PackageKey;

/** Lecture des listes Ansible Galaxy et Terraform, contraintes de version. */
final class GalaxyTerraformTest extends TestCase
{
    public function testConstraints(): void
    {
        $versions = ['4.0.0', '5.0.0', '5.31.0', '5.82.1', '6.0.0-beta1', '6.1.0'];
        // Terraform : « ~> 5.0 » = >= 5.0, < 6.0 ; « ~> 5.31.0 » = >= 5.31.0, < 5.32
        self::assertSame('5.82.1', Constraint::best($versions, '~> 5.0'));
        self::assertSame('5.31.0', Constraint::best($versions, '~> 5.31.0'));
        self::assertSame('5.31.0', Constraint::best($versions, '>= 5.0, < 5.50, != 5.0.0'));
        // Galaxy : virgule = et ; préversions seulement si demandées exactement
        self::assertSame('5.82.1', Constraint::best($versions, '>=5.0.0,<6.0.0'));
        self::assertSame('6.1.0', Constraint::best($versions, '*'));
        self::assertSame('6.0.0-beta1', Constraint::best($versions, '6.0.0-beta1'));
        self::assertSame('5.0.0', Constraint::best($versions, '==5.0.0'));
        self::assertNull(Constraint::best($versions, '> 7'));
        self::assertTrue(Constraint::isValid('~> 1.9'));
        self::assertFalse(Constraint::isValid('~> abc'));
    }

    public function testGalaxySpecs(): void
    {
        self::assertSame(['collection', 'community.general', '>=8.0.0'], GalaxyResolver::parseSpec('community.general >=8.0.0'));
        self::assertSame(['collection', 'community.general', '>=8,<9'], GalaxyResolver::parseSpec('community.general:>=8,<9'));
        self::assertSame(['collection', 'ansible.posix', '*'], GalaxyResolver::parseSpec('ansible.posix'));
        self::assertSame(['role', 'geerlingguy.docker', '7.4.1'], GalaxyResolver::parseSpec('role:geerlingguy.docker 7.4.1'));

        $yaml = <<<'YAML'
            collections:
              - community.general
              - name: ansible.posix
                version: ">=1.5.0"
              - name: https://github.com/org/collection.git
                type: git
            roles:
              - name: geerlingguy.docker
                version: 7.4.1
              - src: https://github.com/org/role
            YAML;
        self::assertSame([
            ['collection', 'community.general', '*'],
            ['collection', 'ansible.posix', '>=1.5.0'],
            ['role', 'geerlingguy.docker', '7.4.1'],
        ], GalaxyResolver::parseRequirementsFile($yaml));
        // Ancien format : liste de rôles
        self::assertSame([['role', 'geerlingguy.nginx', '*']], GalaxyResolver::parseRequirementsFile("- src: geerlingguy.nginx\n"));
    }

    public function testTerraformSpecs(): void
    {
        self::assertSame(['kind' => 'provider', 'host' => 'registry.terraform.io', 'address' => 'hashicorp/aws', 'constraint' => '~> 5.0'], TerraformResolver::parseSpec('hashicorp/aws ~> 5.0'));
        self::assertSame(['kind' => 'provider', 'host' => 'registry.opentofu.org', 'address' => 'hashicorp/aws', 'constraint' => '*'], TerraformResolver::parseSpec('registry.opentofu.org/hashicorp/aws'));
        self::assertSame(['kind' => 'module', 'host' => 'registry.terraform.io', 'address' => 'terraform-aws-modules/vpc/aws', 'constraint' => '5.8.1'], TerraformResolver::parseSpec('terraform-aws-modules/vpc/aws 5.8.1'));
        self::assertSame('cli', TerraformResolver::parseSpec('terraform ~> 1.9')['kind']);
        self::assertSame('invalid', TerraformResolver::parseSpec('aws')['kind']);

        $lock = <<<'HCL'
            provider "registry.terraform.io/hashicorp/aws" {
              version     = "5.31.0"
              constraints = "~> 5.0"
              hashes = ["h1:abc=", "zh:def"]
            }
            provider "registry.terraform.io/hashicorp/random" {
              version = "3.6.0"
            }
            HCL;
        self::assertSame(['registry.terraform.io/hashicorp/aws' => '5.31.0', 'registry.terraform.io/hashicorp/random' => '3.6.0'], TerraformResolver::parseLockFile($lock));

        self::assertSame(['url' => 'https://codeload.github.com/terraform-aws-modules/terraform-aws-vpc/tar.gz/25322b6', 'subdir' => '', 'strip' => true],
            TerraformResolver::archiveUrl('git::https://github.com/terraform-aws-modules/terraform-aws-vpc?ref=25322b6'));
        self::assertSame('modules/vpc-endpoints', TerraformResolver::archiveUrl('git::https://github.com/o/r.git//modules/vpc-endpoints?ref=v1')['subdir']);
        self::assertNull(TerraformResolver::archiveUrl('git::https://gitlab.com/o/r?ref=v1'));

        // Liste noire : provider sans hôte = registre public
        self::assertSame(PackageKey::of(Ecosystem::Terraform, 'hashicorp/aws', '5.31.0'), PackageKey::of(Ecosystem::Terraform, 'registry.terraform.io/hashicorp/aws', '5.31.0'));
    }
}
