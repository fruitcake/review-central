<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The server this process runs on, as far as PHP can see it. Every reading is
 * null where the system does not say, such as outside Linux.
 */
class SystemLoad
{
    /**
     * The one-minute load average: processes running or waiting for a processor.
     */
    public function load(): ?float
    {
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;

        return is_array($load) ? round((float) $load[0], 2) : null;
    }

    public function processors(): ?int
    {
        $info = @file_get_contents('/proc/cpuinfo');

        if ($info === false) {
            return null;
        }

        return max(1, preg_match_all('/^processor\s*:/m', $info));
    }

    /**
     * Memory in use, without what the kernel can hand back at once.
     */
    public function memoryUsedPercent(): ?float
    {
        $info = @file_get_contents('/proc/meminfo');

        if ($info === false
            || ! preg_match('/^MemTotal:\s+(\d+)/m', $info, $total)
            || ! preg_match('/^MemAvailable:\s+(\d+)/m', $info, $available)
            || (int) $total[1] === 0) {
            return null;
        }

        return round((1 - (int) $available[1] / (int) $total[1]) * 100, 2);
    }

    public function diskUsedPercent(string $path): ?float
    {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        return $total === false || $free === false || $total <= 0 ? null : round((1 - $free / $total) * 100, 2);
    }
}
