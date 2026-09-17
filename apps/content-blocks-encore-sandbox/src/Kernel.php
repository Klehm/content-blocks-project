<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * The Docker sandbox mounts this tree at /app, the host runs it in place:
     * a compiled container holds absolute paths, so each gets its own cache.
     */
    public function getCacheDir(): string
    {
        $suffix = str_starts_with($this->getProjectDir(), '/app/') ? '-docker' : '';

        return $this->getProjectDir() . '/var/cache/' . $this->environment . $suffix;
    }
}
