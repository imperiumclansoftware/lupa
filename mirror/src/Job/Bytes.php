<?php

namespace App\Job;

final class Bytes
{
    public static function format(int $bytes): string
    {
        foreach (['Go' => 1 << 30, 'Mo' => 1 << 20, 'ko' => 1 << 10] as $unit => $size) {
            if ($bytes >= $size) {
                return str_replace('.', ',', sprintf('%.1f %s', $bytes / $size, $unit));
            }
        }

        return $bytes.' o';
    }
}
