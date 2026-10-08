<?php

namespace App\Resolver;

use App\Job\JobContext;
use Symfony\Component\Process\Process;

/** Lance un outil externe (npm, composer, pip) dans un dossier temporaire. */
final class Tool
{
    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     */
    public static function run(array $command, string $cwd, JobContext $context, array $env = [], int $timeout = 1800): string
    {
        $process = new Process($command, $cwd, $env + ['NO_COLOR' => '1', 'CI' => '1'], null, $timeout);
        $process->run();
        if (!$process->isSuccessful()) {
            $output = trim($process->getErrorOutput()."\n".$process->getOutput());
            $tail = implode("\n", \array_slice(explode("\n", $output), -25));
            $context->log($tail);
            throw new \RuntimeException(sprintf("La commande « %s » a échoué :\n%s", implode(' ', \array_slice($command, 0, 3)), self::summary($output)));
        }

        return $process->getOutput();
    }

    public static function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(6));
        mkdir($dir, 0o700, true);

        return $dir;
    }

    public static function removeDir(string $dir): void
    {
        if (is_dir($dir)) {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($dir);
        }
    }

    /** Lignes d'erreur les plus parlantes de la sortie d'un outil. */
    private static function summary(string $output): string
    {
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $output)),
            static fn (string $l) => '' !== $l && preg_match('/err|error|not found|no matching|could not|conflict|requires|cannot|unable|ERESOLVE|problem/i', $l),
        ));

        return implode("\n", \array_slice($lines ?: \array_slice(explode("\n", $output), -8), 0, 12));
    }
}
