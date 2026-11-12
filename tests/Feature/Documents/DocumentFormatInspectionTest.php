<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Intake\DocumentInspectionLimits;
use App\Services\Documents\InspectDocumentFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HarmlessDocumentSamples;
use Tests\TestCase;

class DocumentFormatInspectionTest extends TestCase
{
    private array $paths = [];

    private function limits(): DocumentInspectionLimits
    {
        // Explicit restrictive technical test values, not D007 policy/defaults.
        return new DocumentInspectionLimits(100000, 200000, 40);
    }

    private function word(array $extra = []): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'qep68-');
        $this->paths[] = $path;
        HarmlessDocumentSamples::docx($path, $extra);

        return new UploadedFile($path, 'evidence.docx', 'text/plain', null, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_actual_pdf_bytes_and_digest_ignore_browser_mime(): void
    {
        $bytes = HarmlessDocumentSamples::pdf();
        $file = UploadedFile::fake()->createWithContent('evidence.pdf', $bytes);
        $result = (new InspectDocumentFile)->execute($file, $this->limits());
        $this->assertSame('application/pdf', $result->mediaType);
        $this->assertSame(strlen($bytes), $result->byteSize);
        $this->assertSame(hash('sha256', $bytes), $result->sha256);
    }

    public function test_actual_ooxml_structure_is_required_even_when_browser_mime_is_wrong(): void
    {
        $file = $this->word();
        $result = (new InspectDocumentFile)->execute($file, $this->limits());
        $this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $result->mediaType);
        $this->assertSame(hash_file('sha256', $file->getPathname()), $result->sha256);
    }

    public function test_lead_approved_decimal_30mb_cap_cannot_be_overridden(): void
    {
        $this->assertSame(30000000, (new DocumentInspectionLimits(30000000, 60000000, 100))->maximumBytes);
        $this->expectException(\InvalidArgumentException::class);
        new DocumentInspectionLimits(30000001, 60000000, 100);
    }

    public function test_actual_oversized_file_is_rejected_before_finalization(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qep68-');
        $this->paths[] = $path;
        $stream = fopen($path, 'w+b');
        fwrite($stream, HarmlessDocumentSamples::pdf());
        ftruncate($stream, 30000001);
        fclose($stream);
        $this->assertSame(30000001, filesize($path));
        $this->expectException(ValidationException::class);
        (new InspectDocumentFile)->execute(new UploadedFile($path, 'large.pdf', null, null, true),
            new DocumentInspectionLimits(30000000, 60000000, 100));
    }

    public static function invalidFiles(): array
    {
        return [['empty.pdf', ''], ['evidence.pdf', '<?php harmless ?>'], ['evidence.doc', 'legacy'],
            ['evidence.php.pdf', '%PDF-1.4'], ['../evidence.pdf', '%PDF-1.4'],
            ['evidence.pdf', "%PDF-1.4\ntruncated"], ['evidence.docx', HarmlessDocumentSamples::pdf()],
            ['large.pdf', HarmlessDocumentSamples::pdf().str_repeat('x', 100000)],
            ['bad.pdf', str_replace('startxref', 'brokenxref', HarmlessDocumentSamples::pdf())]];
    }

    #[DataProvider('invalidFiles')]
    public function test_invalid_or_disguised_inputs_are_rejected(string $name, string $bytes): void
    {
        $file = UploadedFile::fake()->createWithContent($name, $bytes);
        // UploadedFile normalizes client names; use the explicitly reported raw name for traversal testing.
        $file = new class($file->getPathname(), $name, null, null, true) extends UploadedFile
        {
            public function getClientOriginalName(): string
            {
                return $this->getClientOriginalPath();
            }
        };
        $this->expectException(ValidationException::class);
        (new InspectDocumentFile)->execute($file, $this->limits());
    }

    public static function unsafeWordParts(): array
    {
        return [['../escape.xml', '<x/>'], ['word/vbaProject.bin', 'harmless'], ['word/embeddings/object.bin', 'harmless'],
            ['word/evil.php', 'harmless'], ['word/document.xml', '<not-word/>'],
            ['word/bad.xml', '<!DOCTYPE x [<!ENTITY x SYSTEM "file:///never-read">]><x>&x;</x>'],
            ['word/_rels/document.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId2" TargetMode="External" Target="https://example.invalid"/></Relationships>'],
            ['word/bomb.xml', str_repeat('x', 200001)]];
    }

    #[DataProvider('unsafeWordParts')]
    public function test_unsafe_archive_content_is_rejected_without_extraction(string $entry, string $bytes): void
    {
        $file = $this->word([$entry => $bytes]);
        $this->expectException(ValidationException::class);
        (new InspectDocumentFile)->execute($file, $this->limits());
    }
}
