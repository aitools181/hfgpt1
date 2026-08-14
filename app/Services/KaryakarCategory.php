<?php

namespace App\Services;

use InvalidArgumentException;

class KaryakarCategory
{
    public const CATEGORIES = [
        'Vadil Yuvak Karyakar', 'Vadil Yuvti Karyakar',
        'Yuvak Karyakar', 'Yuvti Karyakar',
        'Kishor Karyakar', 'Kishori Karyakar',
        'Bal Karyakar', 'Balika Karyakar',
    ];

    public function calculate(int $age, string $gender): string
    {
        $gender = strtolower(trim($gender));
        if (! in_array($gender, ['male', 'female'], true) || $age < 0 || $age > 120) {
            throw new InvalidArgumentException('A valid age and Male/Female gender are required.');
        }

        if ($age > 50) return $gender === 'male' ? 'Vadil Yuvak Karyakar' : 'Vadil Yuvti Karyakar';
        if ($age >= 26) return $gender === 'male' ? 'Yuvak Karyakar' : 'Yuvti Karyakar';
        if ($age >= 13) return $gender === 'male' ? 'Kishor Karyakar' : 'Kishori Karyakar';
        return $gender === 'male' ? 'Bal Karyakar' : 'Balika Karyakar';
    }
}
