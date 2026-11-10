<?php

namespace App\Actions\Documents;

use App\Authorization\DocumentWorkspaceAccess;
use App\Models\Document;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Thin application facade for the isolated native UI. Never accepts uploaded bytes or paths. */
final class DocumentWorkspace
{
    public function __construct(private DocumentWorkspaceAccess $access, private CreateDocumentWithVersion $creator,
        private AppendDocumentVersion $appender, private ReadDocumentWorkspace $reader, private ReviseDocumentMetadata $metadata) {}

    public function can(string $operation, ?string $publicId = null): bool
    {
        try {
            return $this->access->within($this->actor(), $operation, $publicId, fn () => true, audit: false);
        } catch (AuthorizationException|ModelNotFoundException) {
            return false;
        }
    }

    public function listing(string $search = '', string $sort = 'created_at', string $direction = 'desc', int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        return $this->reader->listing($this->actor(), $search, $sort, $direction, $page, $perPage);
    }

    public function show(string $publicId): array
    {
        return $this->reader->show($this->actor(), $publicId);
    }

    public function history(string $publicId, string $type, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        return $this->reader->history($this->actor(), $publicId, $type, $page, $perPage);
    }

    public function originalMetadata(string $publicId): array
    {
        return $this->reader->originalMetadata($this->actor(), $publicId);
    }

    public function updateMetadata(string $publicId, array $data, string $expected): void
    {
        $this->metadata->execute($this->actor(), $publicId, $data, $expected);
    }

    public function create(array $data): string
    {
        if (array_diff(array_keys($data), ['title', 'description']) !== []) {
            throw ValidationException::withMessages(['document' => 'حقول الوثيقة غير مسموح بها.']);
        }
        $actor = $this->actor();
        // This precheck is only a hint. Callback authorization occurs again inside
        // the existing completeWrite transaction after out-of-transaction file IO.
        $this->access->within($actor, 'create', null, fn () => null);

        try {
            return $this->access->withSession($actor, function (User $current) use ($data): string {
                $stream = $this->syntheticStream();
                try {
                    $result = $this->creator->execute($current, $data + ['original_filename' => 'fixture.txt'], $stream,
                        function (User $locked, Document $document): void {
                            $this->access->authorize($locked, 'create');
                            app(ManageDocumentWorkspaceAssignments::class)->initializeDocument($locked, $document);
                            $this->access->authorize($locked, 'create');
                        });

                    return $result['document_public_id'];
                } finally {
                    fclose($stream);
                }
            });
        } catch (AuthorizationException $error) {
            $this->deniedWrite($actor, 'create', null);
            throw $error;
        }
    }

    public function append(string $publicId): void
    {
        $actor = $this->actor();
        $this->access->within($actor, 'append', $publicId, fn () => null);
        try {
            $this->access->withSession($actor, function (User $current) use ($publicId): void {
                $stream = $this->syntheticStream();
                try {
                    $this->appender->execute($current, $publicId, ['original_filename' => 'fixture.txt'], $stream,
                        fn (User $locked, Document $document) => $this->access->authorize($locked, 'append', $document));
                } finally {
                    fclose($stream);
                }
            });
        } catch (AuthorizationException $error) {
            $this->deniedWrite($actor, 'append', $publicId);
            throw $error;
        }
    }

    private function deniedWrite(?User $actor, string $operation, ?string $publicId): void
    {
        try {
            app(ManageDocumentWorkspaceAssignments::class)->recordDenied($actor, $operation, $publicId);
        } catch (AuthorizationException) {
            // Removed membership or stale password cannot fabricate an audit actor.
        }
    }

    private function actor(): ?User
    {
        $actor = Auth::guard('institution')->user();

        return $actor instanceof User ? $actor : null;
    }

    private function syntheticStream(): mixed
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'DOCUMENT_LOCAL_001 harmless synthetic fixture '.Str::ulid());
        rewind($stream);

        return $stream;
    }
}
