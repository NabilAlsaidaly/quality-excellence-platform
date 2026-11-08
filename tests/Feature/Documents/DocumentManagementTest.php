<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\AppendDocumentVersion;
use App\Actions\Documents\CreateDocumentWithVersion;
use App\Actions\Documents\ListDocuments;
use App\Actions\Documents\ReadDocumentHistory;
use App\Authorization\TenantContext;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Test fixture helpers stay in this allowlisted file, not a new shared production layer. */
trait DocumentFixtures
{
    private array $documentIds = [];

    protected function prepareDocuments(): void
    {
        Storage::fake('local');
        Event::listen('eloquent.created: '.Document::class, function (Document $document): void {
            $this->documentIds[] = $document->id;
        });
    }

    protected function cleanupDocuments(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach (DB::table('document_versions')->whereIn('document_id', $this->documentIds)->get() as $version) {
            Storage::disk('local')->delete($version->storage_key);
        }
        DB::table('document_versions')->whereIn('document_id', $this->documentIds)->delete();
        DB::table('documents')->whereIn('id', $this->documentIds)->delete();
    }

    protected function member(): OrganizationMembership
    {
        return OrganizationMembership::factory()->create();
    }

    protected function stream(string $bytes = 'synthetic private bytes'): mixed
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    protected function data(array $overrides = []): array
    {
        return array_replace(['title' => 'وثيقة اختبار', 'description' => 'وصف عربي للوثيقة', 'original_filename' => 'اختبار.bin'], $overrides);
    }

    protected function trusted(OrganizationMembership $member, callable $operation): mixed
    {
        return app(TenantContext::class)->run($member->user, $member->organization_id, $operation(...));
    }

    protected function createDocument(OrganizationMembership $member, string $bytes = 'v1', array $overrides = []): array
    {
        $stream = $this->stream($bytes);
        try {
            return $this->trusted($member, fn () => app(CreateDocumentWithVersion::class)->execute($member->user, $this->data($overrides), $stream));
        } finally {
            fclose($stream);
        }
    }

    protected function append(OrganizationMembership $member, string $id, string $bytes = 'v2'): array
    {
        $stream = $this->stream($bytes);
        try {
            return $this->trusted($member, fn () => app(AppendDocumentVersion::class)->execute($member->user, $id, ['original_filename' => 'التالي.bin'], $stream));
        } finally {
            fclose($stream);
        }
    }
}

class DocumentManagementTest extends TestCase
{
    use DatabaseMigrations, DocumentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareDocuments();
    }

    protected function tearDown(): void
    {
        $this->cleanupDocuments();
        parent::tearDown();
    }

    public function test_document_v1_v2_v3_preserve_bytes_identity_attribution_and_bounded_history(): void
    {
        $member = $this->member();
        $first = $this->createDocument($member, "أصل\0bytes");
        $document = Document::sole();
        $version = DocumentVersion::sole();
        $original = $version->fresh()->getAttributes();
        $this->assertSame($member->organization_id, $document->organization_id);
        $this->assertSame($member->user_id, $document->created_by);
        $this->assertSame($member->user_id, $version->actor_id);
        $this->assertSame('وثيقة اختبار', $first['title']);
        $this->assertTrue(Str::isUlid($first['document_public_id']));
        $this->assertTrue(Str::isUlid($first['version_public_id']));
        $this->assertSame('public_id', $document->getRouteKeyName());
        $second = $this->append($member, $first['document_public_id'], 'second');
        // Identical bytes deliberately append again. No business dedup/no-op policy.
        $third = $this->append($member, $first['document_public_id'], 'second');
        $this->assertSame([1, 2, 3], [$first['version_number'], $second['version_number'], $third['version_number']]);
        $this->assertSame($original, $version->fresh()->getAttributes());
        $this->assertSame($first['document_public_id'], $document->fresh()->public_id);
        $this->assertCount(3, DocumentVersion::pluck('storage_key')->unique());
        $this->assertSame(hash('sha256', "أصل\0bytes"), $version->sha256);
        $this->trusted($member, function () use ($member, $first, $version): void {
            $history = app(ReadDocumentHistory::class);
            $this->assertSame(['sha256' => $version->sha256, 'byte_size' => strlen("أصل\0bytes")],
                $history->verify($member->user, $first['document_public_id'], $first['version_public_id']));
            $this->assertSame([3, 2, 1], array_column($history->execute($member->user, $first['document_public_id'])->items(), 'version_number'));
            $this->assertSame([1, 2, 3], array_column($history->execute($member->user, $first['document_public_id'], 'asc')->items(), 'version_number'));
            $page = $history->execute($member->user, $first['document_public_id'], page: 2, perPage: 1);
            $this->assertSame(3, $page->total());
            $this->assertSame(2, $page->items()[0]['version_number']);
            foreach (['id', 'organization_id', 'actor_id', 'storage_disk', 'storage_key', 'sha256', 'original_filename', 'byte_size', 'body'] as $key) {
                $this->assertArrayNotHasKey($key, $page->items()[0]);
                $this->assertArrayNotHasKey($key, $first);
            }
        });
    }

    public function test_list_search_sort_counts_and_details_are_scoped_without_n_plus_one(): void
    {
        $a = $this->member();
        $b = $this->member();
        $foreign = $this->createDocument($b, 'FOREIGN SECRET', ['title' => 'foreign']);
        foreach (['literal%_!', 'second', 'third'] as $title) {
            $this->createDocument($a, 'local', ['title' => $title]);
        }
        $this->trusted($a, function () use ($a, $foreign): void {
            $reads = app(ListDocuments::class);
            $this->assertSame(3, $reads->execute($a->user)->total());
            $this->assertSame(0, $reads->execute($a->user, 'foreign')->total());
            $this->assertSame(1, $reads->execute($a->user, '%_!')->total());
            $this->assertCount(1, $reads->execute($a->user, page: 2, perPage: 2)->items());
            $this->assertSame(['literal%_!', 'second', 'third'], array_column($reads->execute($a->user, sort: 'title', direction: 'asc')->items(), 'title'));
            DB::enableQueryLog();
            $items = $reads->execute($a->user)->items();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertLessThan(9, count($queries));
            $this->assertStringNotContainsString($foreign['document_public_id'], json_encode($items));
            foreach ($items as $item) {
                $this->assertSame(1, $item['versions_count']);
                $this->assertSame(1, $reads->show($a->user, $item['document_public_id'])['latest_version_number']);
            }
        });
        $this->createDocument($a, 'duplicate title allowed', ['title' => 'second']);
        $this->assertDatabaseCount('documents', 5);
    }

    public static function forbiddenKeys(): array
    {
        return array_map(fn ($key) => [$key], ['organization_id', 'actor_id', 'created_by', 'status', 'disk', 'path', 'owner_id', 'public_id', 'sha256', 'byte_size']);
    }

    #[DataProvider('forbiddenKeys')]
    public function test_unknown_authority_identity_and_storage_fields_fail_before_writes(string $key): void
    {
        $member = $this->member();
        try {
            $this->createDocument($member, 'bytes', [$key => 'forged']);
            $this->fail('Unapproved input must be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('documents', 0);
            $this->assertDatabaseCount('document_versions', 0);
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public function test_foreign_missing_malformed_numeric_resources_have_identical_not_found_semantics(): void
    {
        $a = $this->member();
        $b = $this->member();
        $foreign = $this->createDocument($b);
        $local = $this->createDocument($a);
        $messages = [];
        foreach ([$foreign['document_public_id'], (string) Str::ulid(), '1', '../document'] as $id) {
            foreach ([fn () => $this->append($a, $id),
                fn () => $this->trusted($a, fn () => app(ListDocuments::class)->show($a->user, $id)),
                fn () => $this->trusted($a, fn () => app(ReadDocumentHistory::class)->execute($a->user, $id))] as $operation) {
                try {
                    $operation();
                    $this->fail('Invalid resource must be not found.');
                } catch (ModelNotFoundException $error) {
                    $messages[] = $error->getMessage();
                }
            }
        }
        $this->assertCount(1, array_unique($messages));
        foreach ([$foreign['version_public_id'], (string) Str::ulid(), '1', '../version'] as $version) {
            try {
                $this->trusted($a, fn () => app(ReadDocumentHistory::class)->verify($a->user, $local['document_public_id'], $version));
                $this->fail('Version lookup must remain scoped to its document.');
            } catch (ModelNotFoundException $error) {
                $this->assertSame(DocumentVersion::class, $error->getModel());
            }
        }
        $this->assertDatabaseCount('document_versions', 2);
    }

    public function test_missing_context_actor_mismatch_platform_and_revoked_membership_fail_closed(): void
    {
        $a = $this->member();
        $b = $this->member();
        $stream = $this->stream();
        try {
            foreach ([fn () => app(CreateDocumentWithVersion::class)->execute($a->user, $this->data(), $stream),
                fn () => $this->trusted($a, fn () => app(CreateDocumentWithVersion::class)->execute($b->user, $this->data(), $stream)),
                fn () => $this->trusted($a, function () use ($a, $stream) {
                    $a->delete();

                    return app(CreateDocumentWithVersion::class)->execute($a->user, $this->data(), $stream);
                })] as $operation) {
                try {
                    $operation();
                    $this->fail('Untrusted boundary must reject.');
                } catch (AuthorizationException) {
                    $this->assertDatabaseCount('documents', 0);
                }
            }
        } finally {
            fclose($stream);
        }
        $platform = User::factory()->make();
        $platform->principal_type = 'platform';
        $platform->save();
        try {
            app(TenantContext::class)->run($platform, $b->organization_id, fn () => app(ListDocuments::class)->execute($platform));
            $this->fail('Platform principal must never enter tenant content.');
        } catch (AuthorizationException) {
            $this->assertNull(app(TenantContext::class)->organizationId());
        } finally {
            $platform->delete(); // This test's unreferenced synthetic principal only.
        }
        $doc = $this->createDocument($b);
        $b->delete();
        $this->expectException(AuthorizationException::class);
        $this->trusted($b, fn () => app(ReadDocumentHistory::class)->execute($b->user, $doc['document_public_id']));
    }

    public function test_models_block_ordinary_changes_deletion_and_mass_assignment(): void
    {
        $member = $this->member();
        $this->createDocument($member);
        foreach ([Document::sole(), DocumentVersion::sole()] as $model) {
            foreach ([fn () => $model->forceFill(['public_id' => (string) Str::ulid()])->save(), fn () => $model->delete()] as $operation) {
                try {
                    $operation();
                    $this->fail('Historical models must deny mutation.');
                } catch (LogicException) {
                    $this->assertDatabaseCount('documents', 1);
                    $this->assertDatabaseCount('document_versions', 1);
                }
            }
            try {
                $model->fill(['organization_id' => 999, 'storage_key' => 'forged']);
                $this->fail('Mass assignment must deny.');
            } catch (MassAssignmentException) {
                $this->assertNotSame(999, $model->organization_id);
            }
        }
    }

    public function test_pagination_and_query_fields_are_bounded_and_no_routes_or_grants_are_added(): void
    {
        $member = $this->member();
        $doc = $this->createDocument($member);
        foreach ([['sort' => 'sha256'], ['direction' => 'drop'], ['page' => 0], ['perPage' => 101]] as $args) {
            try {
                $this->trusted($member, fn () => app(ListDocuments::class)->execute($member->user, ...$args));
                $this->fail('Unsafe query must reject.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('documents', 1);
            }
        }
        foreach ([['page' => 0], ['perPage' => 21], ['direction' => 'random']] as $args) {
            try {
                $this->trusted($member, fn () => app(ReadDocumentHistory::class)->execute($member->user, $doc['document_public_id'], ...$args));
                $this->fail('Unbounded history must reject.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('document_versions', 1);
            }
        }
        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsString('documents', $route->uri());
        }
        $this->assertSame([], config('authorization.capabilities'));
        $this->assertFalse(Gate::forUser($member->user)->allows('create', Document::class));
        $this->actingAs($member->user)->get('/admin/documents')->assertNotFound();
        $this->post('/institution/'.$member->organization->public_id.'/documents', ['file' => 'forged'])->assertNotFound();
    }
}
