<?php

namespace App\Domain\Documents\Intake;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Explicit fixture parameters; no default file limit, whitelist or operational policy. */
final readonly class IntakeRules
{
    /** @param array<string, list<string>> $mediaTypesByExtension */
    public function __construct(public int $maximumBytes, public array $mediaTypesByExtension)
    {
        if ($maximumBytes < 1 || $maximumBytes >= PHP_INT_MAX || $mediaTypesByExtension === []) {
            throw new InvalidArgumentException('حدد حدود اختبار الملفات صراحة.');
        }
        foreach ($mediaTypesByExtension as $extension => $types) {
            if (! is_string($extension) || ! preg_match('/\A[a-z0-9]{1,10}\z/', $extension)
                || ! is_array($types) || ! array_is_list($types) || $types === []) {
                throw new InvalidArgumentException('تعريف صيغ الاختبار غير صالح.');
            }
            foreach ($types as $type) {
                if (! is_string($type) || ! preg_match('~\A[a-z0-9.+-]+/[a-z0-9.+-]+\z~', $type)) {
                    throw new InvalidArgumentException('تعريف نوع الملف غير صالح.');
                }
            }
        }
    }

    public function validate(string $filename, string $detectedMediaType, int $size): void
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($size < 1 || $size > $this->maximumBytes || ! mb_check_encoding($filename, 'UTF-8')
            || mb_strlen($filename) > 255 || preg_match('/[\/\\\\\x00-\x1F\x7F]/u', $filename)
            || preg_match('/\.(?:php\d*|phtml|phar|exe|dll|com|bat|cmd|ps1|cgi|asp|aspx|js|html?)\b/i', $filename)
            || ! in_array($detectedMediaType, $this->mediaTypesByExtension[$extension] ?? [], true)) {
            throw ValidationException::withMessages(['file' => 'حجم الملف أو اسمه أو نوعه الفعلي غير مقبول في قواعد الاختبار.']);
        }
    }
}
