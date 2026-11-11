<?php

namespace App\Services\Documents;

use App\Domain\Documents\Intake\DocumentInspectionLimits;
use App\Domain\Documents\Intake\InspectedDocument;
use App\Domain\Documents\Intake\IntakeRules;
use DOMDocument;
use DOMXPath;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/** Bounded PDF/OOXML structural screening. No file is released or authorized here. */
final class InspectDocumentFile
{
    public function execute(UploadedFile $file, DocumentInspectionLimits $limits): InspectedDocument
    {
        $path = $file->getRealPath();
        if (! $file->isValid() || $path === false || ! is_file($path)) {
            $this->reject();
        }
        clearstatcache(true, $path);
        $size = filesize($path);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $filename = $file->getClientOriginalName();
        (new IntakeRules($limits->maximumBytes, ['pdf' => ['application/pdf'],
            'docx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']]))
            ->validate($filename, $mime ?: '', $size === false ? 0 : $size);
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'pdf') {
            $this->pdf($path, $limits);
            $type = 'application/pdf';
        } else {
            $this->word($path, $limits);
            $type = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        }
        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            $this->reject();
        }

        return new InspectedDocument($filename, $type, $size, $hash);
    }

    private function pdf(string $path, DocumentInspectionLimits $limits): void
    {
        $bytes = file_get_contents($path, length: $limits->maximumBytes + 1);
        // A conservative classic-xref subset. Not a complete PDF parser or CDR implementation.
        if ($bytes === false || strlen($bytes) > $limits->maximumBytes
            || ! preg_match('/\A%PDF-(?:1\.[0-7]|2\.0)[\r\n]/', $bytes)
            || ! preg_match('/startxref\s+(\d{1,10})\s+%%EOF\s*\z/D', $bytes, $match)
            || substr($bytes, (int) $match[1], 4) !== 'xref' || ! str_contains($bytes, 'trailer')
            || ! preg_match('~/Type\s*/Catalog\b~', $bytes)) {
            $this->reject();
        }
        $names = preg_replace_callback('/#([0-9a-f]{2})/i', fn ($m) => chr(hexdec($m[1])), $bytes);
        if (preg_match('~/(?:Encrypt|JavaScript|JS|Launch|EmbeddedFile|OpenAction|AA)\b~', $names)) {
            $this->reject();
        }
    }

    private function word(string $path, DocumentInspectionLimits $limits): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            $this->reject();
        }
        try {
            if ($zip->numFiles > $limits->maximumArchiveEntries) {
                $this->reject();
            }
            $total = 0;
            $names = [];
            $xml = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false || ! $this->safePart($stat['name']) || isset($names[strtolower($stat['name'])])
                    || ($stat['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_NONE
                    || $stat['size'] < 0 || $stat['size'] > $limits->maximumExpandedBytes - $total) {
                    $this->reject();
                }
                $name = $stat['name'];
                $names[strtolower($name)] = true;
                if (! $zip->getExternalAttributesIndex($i, $system, $attributes) || (($attributes >> 16) & 0170000) === 0120000) {
                    $this->reject();
                }
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $stream = $zip->getStreamIndex($i);
                if ($stream === false) {
                    $this->reject();
                }
                try {
                    $bytes = stream_get_contents($stream, $limits->maximumExpandedBytes - $total + 1);
                } finally {
                    fclose($stream);
                }
                if ($bytes === false || strlen($bytes) !== $stat['size'] || strlen($bytes) > $limits->maximumExpandedBytes - $total
                    || sprintf('%u', crc32($bytes)) !== sprintf('%u', $stat['crc'])) {
                    $this->reject();
                }
                $total += strlen($bytes);
                if (preg_match('/\.(?:xml|rels)\z/i', $name)) {
                    $document = $this->xml($bytes);
                    foreach ($document->getElementsByTagNameNS('*', 'Relationship') as $relationship) {
                        if (strcasecmp($relationship->getAttribute('TargetMode'), 'External') === 0) {
                            $this->reject();
                        }
                    }
                    if (in_array($name, ['[Content_Types].xml', '_rels/.rels', 'word/document.xml'], true)) {
                        $xml[$name] = $document;
                    }
                }
            }
            if (count($xml) !== 3) {
                $this->reject();
            }
            $types = new DOMXPath($xml['[Content_Types].xml']);
            $types->registerNamespace('t', 'http://schemas.openxmlformats.org/package/2006/content-types');
            $relationships = new DOMXPath($xml['_rels/.rels']);
            $relationships->registerNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
            $word = new DOMXPath($xml['word/document.xml']);
            $word->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            if ($types->query('/t:Types/t:Override[@PartName="/word/document.xml"][@ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"]')->length !== 1
                || $relationships->query('/r:Relationships/r:Relationship[@Target="word/document.xml"][@Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"]')->length !== 1
                || $word->query('/w:document/w:body')->length !== 1) {
                $this->reject();
            }
        } finally {
            $zip->close();
        }
    }

    private function safePart(string $name): bool
    {
        return mb_check_encoding($name, 'UTF-8') && $name !== '' && ! preg_match('~[\\\\:\x00-\x1f\x7f]|\A/|(?:\A|/)\.{1,2}(?:/|\z)~', $name)
            && ! preg_match('~(?:\A|/)(?:vbaProject\.bin|embeddings)(?:/|\z)|\.(?:exe|dll|com|js|php\d*|phtml|phar|bat|cmd|ps1|zip|docm)\z~i', $name);
    }

    private function xml(string $bytes): DOMDocument
    {
        if (str_contains($bytes, "\0") || preg_match('/<!\s*(?:DOCTYPE|ENTITY)/i', $bytes)) {
            $this->reject();
        }
        $old = libxml_use_internal_errors(true);
        try {
            $xml = new DOMDocument;
            if (! $xml->loadXML($bytes, LIBXML_NONET) || $xml->doctype !== null) {
                $this->reject();
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['file' => 'تعذر قبول بنية المستند ضمن حدود الفحص التقنية؛ لم يُتح الملف.']);
    }
}
