<?php

namespace Tests\Unit;

use App\Support\Phone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneTest extends TestCase
{
    public static function numbers(): array
    {
        return [
            ['9876543210', '+919876543210'],
            ['98765 43210', '+919876543210'],
            ['09876543210', '+919876543210'],
            ['+91 98765-43210', '+919876543210'],
            ['919876543210', '+919876543210'],
            ['0091 9876543210', '+919876543210'],
            ['+1 415 555 1234', '+14155551234'],
            ['12345', null],
            ['5876543210', null], // not an Indian mobile
            ['', null],
            [null, null],
        ];
    }

    #[DataProvider('numbers')]
    public function test_normalize(?string $raw, ?string $expected): void
    {
        $this->assertSame($expected, Phone::normalize($raw));
    }
}
