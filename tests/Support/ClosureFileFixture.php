<?php

namespace Tests\Support;

use App\Actions\Documents\ManageDocumentFileOperations;
use App\Authorization\DocumentWorkspaceAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/** Explicit local issuer setup only; no HTTP route or normal seeder calls this. */
final class ClosureFileFixture
{
    public function issue(array $f): array
    {
        $proof = $f['document_proof'] + ['file_operation_scope' => 'CLOSURE_FILES_LOCAL_001', 'demo_unscanned_exception' => true];
        $actor = $f['issuer'];
        Auth::guard('institution')->setUser($actor);
        app('session.store')->put(['identity.active_guard' => 'institution', 'institution.organization' => $f['organization']->public_id,
            'institution.password_hash' => $actor->getAuthPassword()]);
        request()->setLaravelSession(app('session.store'));
        $targets = DocumentWorkspaceAccess::runFixture(request(), $proof, function () use ($f): array {
            $manager = app(ManageDocumentFileOperations::class);
            $targets = [];
            foreach (['pdf', 'docx'] as $format) {
                $document = strtolower((string) Str::ulid());
                foreach ([[$f['author'], ['view', 'metadata', 'history']], [$f['reviewer'], ['view', 'history']]] as [$recipient, $operations]) {
                    foreach ($operations as $operation) {
                        $manager->issue($f['issuer'], $recipient, $operation, $document);
                    }
                }
                $versions = [];
                for ($number = 1; $number <= 3; $number++) {
                    $version = strtolower((string) Str::ulid());
                    $manager->issue($f['issuer'], $f['author'], $number === 1 ? 'upload_real' : 'append_real', $document, $version);
                    $manager->issue($f['issuer'], $f['reviewer'], 'release_demo_unscanned', $document, $version);
                    foreach (['author', 'reviewer'] as $name) {
                        $manager->issue($f['issuer'], $f[$name], 'download_demo_unscanned', $document, $version);
                    }
                    $versions[] = $version;
                }
                $targets[$format] = ['document' => $document, 'versions' => $versions];
            }

            return $targets;
        });

        return ['proof' => $proof, 'targets' => $targets];
    }
}
