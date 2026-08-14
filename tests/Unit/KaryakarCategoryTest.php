<?php

namespace Tests\Unit;

use App\Services\KaryakarCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class KaryakarCategoryTest extends TestCase
{
    #[DataProvider('categoryCases')]
    public function test_age_and_gender_map_to_required_category(int $age, string $gender, string $expected): void
    {
        $this->assertSame($expected, (new KaryakarCategory())->calculate($age, $gender));
    }

    public static function categoryCases(): array
    {
        return [
            [51, 'male', 'Vadil Yuvak Karyakar'], [51, 'female', 'Vadil Yuvti Karyakar'],
            [26, 'male', 'Yuvak Karyakar'], [50, 'female', 'Yuvti Karyakar'],
            [13, 'male', 'Kishor Karyakar'], [25, 'female', 'Kishori Karyakar'],
            [0, 'male', 'Bal Karyakar'], [12, 'female', 'Balika Karyakar'],
        ];
    }
}
