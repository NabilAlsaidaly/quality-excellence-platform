<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Public-safe metadata projection. No filename/hash/bytes/creator contact or key. */
final class ListDocuments
{
    public function __construct(private CreateDocumentWithVersion $boundary) {}

    public function execute(?User $actor, string $search = '', string $sort = 'created_at', string $direction = 'desc', int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        return $this->boundary->withinContext($actor, function (Organization $organization) use ($search, $sort, $direction, $page, $perPage): LengthAwarePaginator {
            if (! in_array($sort, ['title', 'created_at'], true) || ! in_array($direction, ['asc', 'desc'], true)
                || $page < 1 || $page > 1000000 || $perPage < 1 || $perPage > 100 || mb_strlen($search) > 255 || ! mb_check_encoding($search, 'UTF-8')) {
                throw ValidationException::withMessages(['search' => 'معايير البحث أو التصفح غير صالحة.']);
            }
            $query = Document::query()->where('organization_id', $organization->id)->select('documents.*');
            foreach (['versions_count' => 'COUNT(*)', 'latest_version_number' => 'MAX(version_number)'] as $name => $expression) {
                $query->selectSub(DocumentVersion::query()->selectRaw($expression)->where('organization_id', $organization->id)
                    ->whereColumn('document_id', 'documents.id'), $name);
            }
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $results = $query->when($search !== '', fn ($q) => $q->whereRaw("title LIKE ? ESCAPE '!'", [$pattern]))
                ->orderBy($sort, $direction)->orderBy('id', $direction)->paginate($perPage, ['*'], 'page', $page)->withPath('');
            $results->setCollection($results->getCollection()->map(fn (Document $document) => self::metadata($document) + [
                'versions_count' => (int) $document->versions_count,
                'latest_version_number' => $document->latest_version_number === null ? null : (int) $document->latest_version_number,
            ]));

            return $results;
        });
    }

    public function show(?User $actor, string $publicId): array
    {
        return $this->boundary->withinContext($actor, function (Organization $organization) use ($publicId): array {
            $document = self::find($organization->id, $publicId);
            $last = $document->versions()->orderByDesc('version_number')->lockForUpdate()->first();

            return self::metadata($document) + ['latest_version_number' => $last?->version_number, 'versions_count' => $document->versions()->count()];
        });
    }

    /** Current scoped lookup, called only inside the trusted boundary. */
    public static function find(int $organizationId, string $publicId): Document
    {
        $document = Str::isUlid($publicId) ? Document::query()->where('organization_id', $organizationId)
            ->where('public_id', $publicId)->lockForUpdate()->first() : null;
        if ($document === null) {
            throw (new ModelNotFoundException)->setModel(Document::class);
        }

        return $document;
    }

    public static function metadata(Document $document): array
    {
        return ['document_public_id' => $document->public_id, 'title' => $document->title, 'description' => $document->description,
            'created_at' => $document->created_at->toIso8601String()];
    }
}
