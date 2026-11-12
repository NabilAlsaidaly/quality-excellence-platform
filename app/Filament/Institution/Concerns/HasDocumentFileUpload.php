<?php

namespace App\Filament\Institution\Concerns;

use App\Actions\Documents\DocumentFileWorkspace;
use App\Authorization\DocumentWorkspaceAccess;
use App\Filament\Institution\Resources\DocumentResource;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Support\Components\Attributes\Exposed;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Facades\GenerateSignedUploadUrlFacade;

/** Native upload transport; the existing application Action owns all durable writes. */
trait HasDocumentFileUpload
{
    protected function realUploadAction(string $operation, ?string $document = null): Action
    {
        $creating = $operation === 'upload_real';

        return Action::make($creating ? 'uploadFile' : 'appendFile')
            ->label($creating ? 'رفع وثيقة PDF أو DOCX' : 'إضافة نسخة من ملف')
            ->modalSubmitActionLabel('حفظ النسخة')
            ->modalDescription('عينات اصطناعية معدّة للعرض المحلي فقط، بحد أقصى 30,000,000 بايت. الحفظ لا يعني الفحص أو الإتاحة.')
            ->visible(fn () => app(DocumentFileWorkspace::class)->permits($operation, $document) !== [])
            ->fillForm(function () use ($operation, $document): array {
                $permit = app(DocumentFileWorkspace::class)->permits($operation, $document)[0] ?? abort(403);

                return ['grant_id' => $permit['grant_public_id'], 'request_key' => (string) Str::ulid(),
                    'expected_version' => $document === null ? 'none' : app(DocumentFileWorkspace::class)->latestVersion($document)];
            })
            ->schema([Hidden::make('grant_id')->required(), Hidden::make('request_key')->required(), Hidden::make('expected_version')->required(),
                ...($creating ? DocumentResource::metadataFields() : []),
                FileUpload::make('file')->label('الملف')->required()->multiple(false)->storeFiles(false)
                    ->previewable(false)->openable(false)->downloadable(false)->maxSize(29297)
                    ->acceptedFileTypes(['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])
                    ->helperText('PDF أو DOCX فقط. تُحفظ كل نسخة مستقلة ولا تستبدل النسخ السابقة.')])
            ->action(function (array $data) use ($creating): void {
                try {
                    $result = app(DocumentFileWorkspace::class)->upload($data['file'], $creating
                        ? ['title' => $data['title'], 'description' => $data['description']] : [],
                        $data['request_key'], $data['grant_id'], $data['expected_version']);
                } catch (ValidationException $error) {
                    $path = $this->getMountedActionSchema()->getStatePath();
                    throw ValidationException::withMessages(collect($error->errors())->mapWithKeys(fn ($messages, $key) => [
                        $path.'.'.(in_array($key, ['title', 'description'], true) ? $key : 'file') => $messages,
                    ])->all());
                }
                Notification::make()->title('حُفظت النسخة دون تغيير النسخ السابقة')->body('لم يبدأ فحص البرمجيات الخبيثة؛ النسخة غير متاحة حتى صدور قرار مستقل.')->success()->send();
                $this->redirect($this->getResourceUrl('workspace', ['document' => $result['document_public_id']]));
            });
    }

    protected function assertMountedUpload(string $name): array
    {
        abort_unless(DocumentWorkspaceAccess::hasFileScope() && count($this->mountedActions ?? []) === 1
            && ($name === 'mountedActions.0.data.file'
                || preg_match('/\AmountedActions\.0\.data\.file\.[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/D', $name)), 403);
        $operation = match ($this->getMountedAction()?->getName()) {
            'uploadFile' => 'upload_real', 'appendFile' => 'append_real', default => abort(403),
        };
        $grant = $this->mountedActions[0]['data']['grant_id'] ?? '';
        $permit = collect(app(DocumentFileWorkspace::class)->permits($operation, $operation === 'append_real' ? $this->document : null))
            ->firstWhere('grant_public_id', $grant);
        abort_unless($permit !== null, 403);

        return $permit;
    }

    #[Exposed]
    public function _startUpload($name, $fileInfo, $isMultiple): void
    {
        $permit = $this->assertMountedUpload($name);
        abort_unless($isMultiple === false && count($fileInfo) === 1 && is_numeric($fileInfo[0]['size'] ?? null)
            && $fileInfo[0]['size'] > 0 && $fileInfo[0]['size'] <= 30000000, 422);
        $url = GenerateSignedUploadUrlFacade::signedRoute('livewire.upload-file', now()->addMinutes(5), [
            'closure_grant' => $permit['grant_public_id'], 'closure_actor' => Auth::guard('institution')->user()->public_id,
            'closure_fixture' => DocumentWorkspaceAccess::context()['fixture_id'],
        ]);
        $this->dispatch('upload:generatedSignedUrl', name: $name, url: $url)->self();
    }

    #[Exposed]
    public function _finishUpload($name, $tmpPath, $isMultiple, $append = true): void
    {
        $this->assertMountedUpload($name);
        abort_unless($isMultiple === false && count($tmpPath) === 1, 403);
        parent::_finishUpload($name, $tmpPath, $isMultiple, $append);
    }
}
