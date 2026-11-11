<?php

declare(strict_types=1);

namespace App\Http\Requests\Institution;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class MoveOrganizationalUnitRequest extends FormRequest
{
    /**
     * Authorization is enforced by AuthorizedUnitOperations.
     * This request is responsible only for input validation.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('parent_public_id') === '') {
            $this->merge(['parent_public_id' => null]);
        }
    }

    public function rules(): array
    {
        return [
            'parent_public_id' => [
                'nullable',
                'string',
                'ulid',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowedFields = [
                'parent_public_id',
                '_token',
            ];

            $unsupportedFields = array_diff(
                array_keys($this->all()),
                $allowedFields,
            );

            foreach ($unsupportedFields as $field) {
                $validator->errors()->add(
                    (string) $field,
                    'هذا الحقل غير مسموح به.',
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'parent_public_id.string' => 'معرّف الوحدة الأعلى غير صالح.',
            'parent_public_id.ulid' => 'معرّف الوحدة الأعلى يجب أن يكون ULID صالحًا.',
        ];
    }

    public function attributes(): array
    {
        return [
            'parent_public_id' => 'الوحدة الأعلى',
        ];
    }
}
