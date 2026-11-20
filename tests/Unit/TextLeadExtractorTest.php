<?php

namespace Tests\Unit;

use App\Services\Agency\TextLeadExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TextLeadExtractorTest extends TestCase
{
    private function extract(string $text): array
    {
        return (new TextLeadExtractor)->extract($text);
    }

    public function test_labelled_numbered_list(): void
    {
        $text = "New leads from Happy Events:\n1) Name: Kavya Iyer, Phone: 98123 45604, City: Pune\n2) Name: Manish Gupta\nMobile: +91 9812345605\nCity: Thane\nBoth want birthday party packages for Dec.";

        $this->assertSame([
            ['name' => 'Kavya Iyer', 'phone' => '+919812345604', 'city' => 'Pune', 'ambiguous' => false],
            ['name' => 'Manish Gupta', 'phone' => '+919812345605', 'city' => 'Thane', 'ambiguous' => false],
        ], $this->extract($text));
    }

    public static function formats(): array
    {
        return [['9812345604'], ['+91 98123 45604'], ['+91-98123-45604'], ['098123 45604'], ['919812345604'], ['0091 98123 45604'], ['98123.45604']];
    }

    #[DataProvider('formats')]
    public function test_indian_number_formats(string $phone): void
    {
        $this->assertSame('+919812345604', $this->extract("Call {$phone} please")[0]['phone']);
    }

    public function test_unlabelled_lines_take_name_before_number(): void
    {
        $found = $this->extract("Kavya Iyer - 9812345604 - Pune\nManish Gupta 9812345605");

        $this->assertSame(['Kavya Iyer', 'Manish Gupta'], array_column($found, 'name'));
        $this->assertSame([false, false], array_column($found, 'ambiguous'));
    }

    public function test_two_numbers_for_one_person_are_flagged(): void
    {
        $found = $this->extract("Name: Ravi Kumar\nPhone: 9812345604 / 9812345605\nCity: Pune");

        $this->assertCount(2, $found);
        $this->assertSame(['Ravi Kumar', 'Ravi Kumar'], array_column($found, 'name'));
        $this->assertSame([true, true], array_column($found, 'ambiguous'));
    }

    public function test_name_without_phone_needs_review_and_noise_is_ignored(): void
    {
        $this->assertSame([['name' => 'Lata Menon', 'phone' => null, 'city' => 'Thane', 'ambiguous' => true]],
            $this->extract("Name: Lata Menon\nCity: Thane"));
        $this->assertSame([], $this->extract('Kids: 25, budget 15000, date 14/12/2026, pin 400053, landline 022 2634 5678'));
    }

    public function test_agency_name_label_is_not_the_customer_name(): void
    {
        $found = $this->extract("Agency name: Happy Events\nPhone: 9812345604");

        $this->assertNull($found[0]['name']);
        $this->assertSame('+919812345604', $found[0]['phone']);
    }
}
