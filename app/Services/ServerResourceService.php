<?php

namespace App\Services;

// Reads the host's current CPU load and RAM usage straight from Linux's /proc, with no shell_exec
// (routinely disabled on shared hosting for security). Best-effort only: many cPanel accounts
// run under open_basedir restrictions that block /proc entirely, or don't expose it as a real
// system view at all in a container/vhost context - every method returns null rather than
// throwing when a number just isn't available, and the UI hides that piece instead of erroring.
class ServerResourceService
{
    // The 1-minute load average - not a literal "CPU %" (that needs a stateful delta between two
    // /proc/stat reads, not worth the complexity here), but the standard, universally-understood
    // Linux number for "how busy is this box right now."
    public function loadAverage(): ?float
    {
        if (! function_exists('sys_getloadavg')) {
            return null;
        }

        $load = @sys_getloadavg();

        return is_array($load) && isset($load[0]) ? round($load[0], 2) : null;
    }

    public function memoryUsagePercent(): ?float
    {
        if (! is_readable('/proc/meminfo')) {
            return null;
        }

        $lines = @file('/proc/meminfo');

        if (! $lines) {
            return null;
        }

        $meminfo = [];

        foreach ($lines as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $matches)) {
                $meminfo[$matches[1]] = (int) $matches[2];
            }
        }

        if (empty($meminfo['MemTotal'])) {
            return null;
        }

        $available = $meminfo['MemAvailable'] ?? $meminfo['MemFree'] ?? null;

        if ($available === null) {
            return null;
        }

        $used = $meminfo['MemTotal'] - $available;

        return round(($used / $meminfo['MemTotal']) * 100, 1);
    }
}
