<?php

namespace App\Tests\Unit\Resolver;

use App\Resolver\PypiPlatforms;
use PHPUnit\Framework\TestCase;

final class PypiPlatformsTest extends TestCase
{
    public function testLinuxX86(): void
    {
        $tags = PypiPlatforms::tags('linux-x86_64');

        foreach (['manylinux2014_x86_64', 'manylinux_2_28_x86_64', 'manylinux_2_17_x86_64', 'manylinux1_x86_64', 'manylinux2010_x86_64'] as $tag) {
            self::assertContains($tag, $tags);
        }
        // Les étiquettes les plus récentes d'abord
        self::assertSame('manylinux_2_39_x86_64', $tags[0]);
    }

    public function testLinuxArmHasNoLegacyTags(): void
    {
        $tags = PypiPlatforms::tags('linux-aarch64');

        self::assertContains('manylinux2014_aarch64', $tags);
        self::assertContains('manylinux_2_28_aarch64', $tags);
        self::assertNotContains('manylinux1_aarch64', $tags);
        self::assertSame([], array_filter($tags, static fn ($t) => !str_ends_with($t, '_aarch64')));
    }

    public function testMacosArm(): void
    {
        $tags = PypiPlatforms::tags('macos-arm64');

        self::assertContains('macosx_11_0_arm64', $tags);
        self::assertContains('macosx_14_0_arm64', $tags);
        // Pas de wheels arm64 avant macOS 11, mais les universal2 marquées 10.x conviennent
        self::assertSame([], preg_grep('/^macosx_10_\d+_arm64$/', $tags));
        self::assertContains('macosx_10_9_universal2', $tags);
        self::assertNotContains('macosx_11_0_x86_64', $tags);
    }

    public function testMacosIntel(): void
    {
        $tags = PypiPlatforms::tags('macos-x86_64');

        self::assertContains('macosx_10_9_x86_64', $tags);
        self::assertContains('macosx_10_9_intel', $tags);
        self::assertNotContains('macosx_11_0_arm64', $tags);
    }

    public function testEveryLabelledPlatformHasUniqueTags(): void
    {
        foreach (array_keys(PypiPlatforms::labels()) as $platform) {
            $tags = PypiPlatforms::tags($platform);
            self::assertNotEmpty($tags, $platform);
            self::assertSame($tags, array_values(array_unique($tags)), $platform);
        }
        self::assertSame(['win_amd64'], PypiPlatforms::tags('windows-amd64'));
        self::assertSame(['musllinux_1_2_x86_64', 'musllinux_1_1_x86_64'], PypiPlatforms::tags('musllinux-x86_64'));
    }

    public function testUnknownPlatformThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PypiPlatforms::tags('solaris-sparc');
    }
}
