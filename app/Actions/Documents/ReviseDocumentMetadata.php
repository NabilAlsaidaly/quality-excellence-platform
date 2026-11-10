<?php

namespace App\Actions\Documents;

use App\Authorization\DocumentWorkspaceAccess;
use App\Models\Document;
use App\Models\DocumentMetadataRevision;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ReviseDocumentMetadata
{
    public function __construct(private DocumentWorkspaceAccess $access, private CreateDocumentWithVersion $boundary) {}

    public function execute(?User $actor, string $publicId, array $data, string $expected): void
    {
        $this->access->within($actor, 'metadata', $publicId, function (User $current, Document $document) use ($data, $expected): void {
            if (array_diff(array_keys($data), ['title', 'description']) !== [] || ($expected !== 'none' && ! Str::isUlid($expected))) {
                throw ValidationException::withMessages(['document' => 'حقول البيانات أو رمز التحرير غير صالحة.']);
            }
            $this->boundary->validateMetadata($data + ['original_filename' => 'fixture.txt'], true);
            $last = DocumentMetadataRevision::where('organization_id', $document->organization_id)->where('document_id', $document->id)
                ->orderByDesc('version_number')->lockForUpdate()->first();
            if (! hash_equals($last?->public_id ?? 'none', $expected)) {
                throw new ConflictHttpException('تغيرت بيانات الوثيقة؛ أعد فتح النسخة الحالية.');
            }
            if (($last?->title ?? $document->title) === $data['title'] && ($last?->description ?? $document->description) === $data['description']) {
                return;
            }
            if ($last !== null && $last->version_number >= 4294967295) {
                throw ValidationException::withMessages(['document' => 'بلغ ترقيم البيانات الحد التقني.']);
            }
            $revision = new DocumentMetadataRevision;
            $revision->forceFill(['organization_id' => $document->organization_id, 'document_id' => $document->id,
                'actor_id' => $current->id, 'version_number' => ($last?->version_number ?? 0) + 1,
                'title' => $data['title'], 'description' => $data['description'], 'created_at' => DocumentWorkspaceAccess::timestamp()])->save();
        });
    }
}
