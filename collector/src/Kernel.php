<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }

    /** API d'édition de schéma de Doctrine ORM 3.6 (l'ancienne est retirée en ORM 4) */
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition('doctrine.orm.default_configuration')) {
            $container->getDefinition('doctrine.orm.default_configuration')->addMethodCall('setUseSchemaEditorApi', [true]);
        }
    }
}
