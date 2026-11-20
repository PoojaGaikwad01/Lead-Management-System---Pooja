<?php

namespace App\Services\Agency;

/**
 * Rule-based extraction of leads from an agency's free-text WhatsApp message.
 * One candidate per Indian mobile number found; "Name:" / "City:" labels give the details.
 * A candidate is "ambiguous" (→ needs review) when it has no phone, or shares its record with other numbers.
 */
class TextLeadExtractor
{
    /** 10-digit Indian mobile, optionally +91 / 91 / 0091 / 0 prefixed, with spaces, dashes or dots between digits. */
    public const PHONE = '/(?<![\d+])(?:(?:\+|00)?\s?91[\s\-.]*|0)?([6-9](?:[\s\-.]?\d){9})(?!\d)/';

    private const MARKER = '/^\s*(?:\d{1,3}[.)]|[-•*▪➤►])\s+/u';

    private const NAME = '/(?<!agency\s)(?<!company\s)\b(?:name|customer name|client name|parent name)\s*[:\-–]\s*([^\n,;|]+)/iu';

    private const CITY = '/\b(?:city|location|area|locality|place)\s*[:\-–]\s*([^\n,;|]+)/iu';

    private const LABEL_WORDS = '/\b(?:phone|mobile|mob|contact|number|no|ph|cell|whatsapp|tel|city|location|area|email|name)\b\.?\s*[:\-–]?/iu';

    /** @return list<array{name: ?string, phone: ?string, city: ?string, ambiguous: bool}> */
    public function extract(string $text): array
    {
        $candidates = [];

        foreach ($this->records($text) as $record) {
            $phones = self::phones($record);
            $name = $this->label(self::NAME, $record);
            $city = $this->label(self::CITY, $record);

            if (! $phones) {
                if ($name) {
                    $candidates[] = ['name' => $name, 'phone' => null, 'city' => $city, 'ambiguous' => true];
                }

                continue;
            }

            foreach ($phones as $phone => $before) {
                $candidates[] = [
                    'name' => $name ?? $this->nameBefore($before),
                    'phone' => $phone,
                    'city' => $city,
                    'ambiguous' => count($phones) > 1,
                ];
            }
        }

        return $candidates;
    }

    /** All Indian mobiles in $text as [+91XXXXXXXXXX => text before it on its line]. */
    public static function phones(string $text): array
    {
        $found = [];
        foreach (preg_split('/\R/u', $text) as $line) {
            if (preg_match_all(self::PHONE, $line, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as $i => [, $offset]) {
                    $found['+91'.preg_replace('/\D/', '', $m[1][$i][0])] ??= substr($line, 0, $offset);
                }
            }
        }

        return $found;
    }

    /**
     * Split into one record per lead. A new record starts when the current one already has a phone and the line is
     * blank, a list item ("1)", "-", "•"), a "Name:" line, or another number in an unlabelled list;
     * or when a second "Name:" label appears.
     */
    private function records(string $text): array
    {
        $records = [];
        $current = [];

        foreach (preg_split('/\R/u', trim($text)) as $line) {
            $currentText = implode("\n", $current);
            $hasPhone = (bool) self::phones($currentText);
            $hasName = (bool) preg_match(self::NAME, $currentText);
            $lineHasName = (bool) preg_match(self::NAME, $line);
            $blank = trim($line) === '';

            $split = ($hasPhone && ($blank || preg_match(self::MARKER, $line) || $lineHasName || (self::phones($line) && ! $hasName)))
                || ($hasName && $lineHasName);

            if ($split && $current) {
                $records[] = $currentText;
                $current = [];
            }
            if (! $blank) {
                $current[] = $line;
            }
        }
        if ($current) {
            $records[] = implode("\n", $current);
        }

        return $records;
    }

    private function label(string $pattern, string $record): ?string
    {
        if (! preg_match($pattern, $record, $m)) {
            return null;
        }
        // "Name: Kavya Iyer Phone: 98…" → cut at the next label; drop digits.
        $value = preg_split(self::LABEL_WORDS, $m[1])[0];
        $value = trim(preg_replace('/[\d+]/', '', $value), " \t-–:.()/");

        return preg_match('/\p{L}/u', $value) && mb_strlen($value) <= 60 ? $value : null;
    }

    /** Unlabelled lists like "Kavya Iyer - 98123 45604": the words before the number. */
    private function nameBefore(string $before): ?string
    {
        $candidate = preg_replace([self::MARKER, self::LABEL_WORDS], '', $before);
        $candidate = trim($candidate, " \t-–:|,.()");

        return preg_match("/^\p{L}[\p{L} .']{1,48}$/u", $candidate) ? $candidate : null;
    }
}
