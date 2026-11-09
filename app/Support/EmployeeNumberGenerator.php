<?php

namespace App\Support;

use App\Models\Employee;

class EmployeeNumberGenerator
{
    public const ROLE_FACULTY = 'FAC';

    public const ROLE_COORDINATOR = 'COOR';

    public const ROLE_ADMIN = 'ADMIN';

    public function departmentCode(string $department): string
    {
        return in_array($department, \App\Models\Program::codes(), true) ? strtoupper($department) : 'GEN';
    }

    /**
     * e.g. SITE-IT-FAC, SITE-ENGR-COOR
     */
    public function buildPrefix(string $department, string $role): string
    {
        return 'SITE-' . $this->departmentCode($department) . '-' . $role;
    }

    /**
     * Next number: SITE-IT-FAC001, SITE-ENGR-COOR002, etc.
     * Only counts existing numbers with the same department+role prefix.
     */
    public function next(string $department, string $role): string
    {
        $prefix = $this->buildPrefix($department, $role);
        $max = 0;

        Employee::query()
            ->where('employee_no', 'like', $prefix . '%')
            ->pluck('employee_no')
            ->each(function (string $number) use ($prefix, &$max) {
                if (preg_match('/^' . preg_quote($prefix, '/') . '(\d{3})$/i', $number, $matches)) {
                    $max = max($max, (int) $matches[1]);
                }
            });

        return $prefix . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string, string>
     */
    public function previewMap(string $role): array
    {
        return collect(\App\Models\Program::codes())->mapWithKeys(fn ($program) => [$program => $this->next($program, $role)])->all();
    }
}
