<?php

namespace App\Actions\Documents;

use App\Authorization\DocumentWorkspaceAccess;
use App\Authorization\TenantContext;
use App\Models\Document;
use App\Models\DocumentMetadataRevision;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Explicit typed projections only. No blob/filename/hash/key/download API. */
final class ReadDocumentWorkspace
{
    public function __construct(private DocumentWorkspaceAccess $access) {}

    public function listing(?User $actor, string $search = '', string $sort = 'created_at', string $direction = 'desc', int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        return $this->access->within($actor, 'list', null, function (User $current) use ($search, $sort, $direction, $page, $perPage): LengthAwarePaginator {
            $this->paging($page, $perPage);
            if (! mb_check_encoding($search, 'UTF-8') || mb_strlen($search) > 255 || ! in_array($sort, ['title', 'created_at'], true)
                || ! in_array($direction, ['asc', 'desc'], true)) {
                throw ValidationException::withMessages(['search' => 'معايير البحث أو الترتيب غير صالحة.']);
            }
            $query = $this->access->constrain(Document::query()->where('documents.organization_id', app(TenantContext::class)->organizationId()), $current);
            // Joined latest revision remains a current read under RR; no outer-only
            // lock around a potentially stale scalar/EXISTS subquery is assumed.
            $query->leftJoin('document_metadata_revisions as metadata', fn ($join) => $join
                ->on('metadata.document_id', '=', 'documents.id')->on('metadata.organization_id', '=', 'documents.organization_id'))
                ->leftJoin('document_metadata_revisions as newer', fn ($join) => $join
                    ->on('newer.document_id', '=', 'metadata.document_id')->on('newer.organization_id', '=', 'metadata.organization_id')
                    ->on('newer.version_number', '>', 'metadata.version_number'))->whereNull('newer.id')
                ->select(['documents.public_id', 'documents.created_at', DB::raw('COALESCE(metadata.title,documents.title) AS title'),
                    DB::raw('COALESCE(metadata.description,documents.description) AS description'), 'metadata.public_id as metadata_token']);
            if ($search !== '') {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
                $query->whereRaw("COALESCE(metadata.title,documents.title) LIKE ? ESCAPE '!'", [$pattern]);
            }
            $query->orderBy($sort === 'title' ? 'title' : 'documents.created_at', $direction)->orderBy('documents.id', $direction);
            $paginator = $query->paginate($perPage, ['*'], 'page', $page);
            $paginator->setCollection($paginator->getCollection()->map(fn ($row) => [
                'document_public_id' => $row->public_id, 'title' => $row->title, 'description' => $row->description,
                'metadata_token' => $row->metadata_token ?? 'none', 'created_at' => CarbonImmutable::parse($row->created_at, 'UTC')->toIso8601String(),
            ]));

            return $paginator;
        });
    }

    public function show(?User $actor, string $publicId): array
    {
        return $this->access->within($actor, 'view', $publicId, function (User $current, Document $document): array {
            $last = DocumentMetadataRevision::where('organization_id', $document->organization_id)->where('document_id', $document->id)
                ->orderByDesc('version_number')->lockForUpdate()->first();

            return ['document_public_id' => $document->public_id, 'title' => $last?->title ?? $document->title,
                'description' => $last?->description ?? $document->description, 'metadata_token' => $last?->public_id ?? 'none',
                'created_at' => $document->created_at->toIso8601String()];
        });
    }

    public function history(?User $actor, string $publicId, string $type, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        return $this->access->within($actor, 'history', $publicId, function (User $current, Document $document) use ($type, $page, $perPage): LengthAwarePaginator {
            $this->paging($page, $perPage);
            if (! in_array($type, ['versions', 'metadata'], true)) {
                throw ValidationException::withMessages(['history' => 'نوع السجل غير صالح.']);
            }
            $table = $type === 'versions' ? 'document_versions' : 'document_metadata_revisions';
            $columns = ['entry.public_id', 'entry.version_number', 'entry.created_at', 'actor.public_id as actor_public_id', 'actor.name as actor_name'];
            if ($type === 'metadata') {
                $columns = [...$columns, 'entry.title', 'entry.description'];
            }
            $rows = DB::table($table.' as entry')->leftJoin('users as actor', fn ($join) => $join
                ->on('actor.id', '=', 'entry.actor_id')->on('actor.organization_id', '=', 'entry.organization_id'))
                ->where('entry.organization_id', $document->organization_id)->where('entry.document_id', $document->id)
                ->select($columns)->orderByDesc('entry.version_number')->sharedLock()->paginate($perPage, ['*'], 'page', $page);
            $rows->setCollection($rows->getCollection()->map(function ($row) use ($type): array {
                $data = ['public_id' => $row->public_id, 'version_number' => (int) $row->version_number,
                    'created_at' => CarbonImmutable::parse($row->created_at, 'UTC')->toIso8601String(),
                    'actor_public_id' => $row->actor_public_id, 'actor_name' => $row->actor_name];

                return $type === 'metadata' ? $data + ['title' => $row->title, 'description' => $row->description] : $data;
            }));

            return $rows;
        });
    }

    public function originalMetadata(?User $actor, string $publicId): array
    {
        return $this->access->within($actor, 'history', $publicId, fn (User $current, Document $document) => [
            'title' => $document->title, 'description' => $document->description, 'created_at' => $document->created_at->toIso8601String(),
        ]);
    }

    private function paging(int $page, int $perPage): void
    {
        if ($page < 1 || $perPage < 1 || $perPage > 100) {
            throw ValidationException::withMessages(['page' => 'حدود الصفحة غير صالحة.']);
        }
    }
}
