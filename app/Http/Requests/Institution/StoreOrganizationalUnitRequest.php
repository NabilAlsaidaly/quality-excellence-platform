<?php

declare(strict_types=1);

namespace App\Http\Requests\Institution;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StoreOrganizationalUnitRequest extends FormRequest
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
        $name = $this->input('name');

        if (is_string($name)) {
            $this->merge(['name' => trim($name)]);
        }

        if ($this->input('parent_public_id') === '') {
            $this->merge(['parent_public_id' => null]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                'regex:/[^\p{Z}\s]/u',
            ],
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
                'name',
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
            'name.required' => 'اسم الوحدة مطلوب.',
            'name.string' => 'يجب أن يكون اسم الوحدة نصًا.',
            'name.max' => 'يجب ألا يتجاوز اسم الوحدة 255 محرفًا.',
            'name.regex' => 'يجب أن يحتوي اسم الوحدة على حرف واحد على الأقل.',
            'parent_public_id.string' => 'معرّف الوحدة الأعلى غير صالح.',
            'parent_public_id.ulid' => 'معرّف الوحدة الأعلى يجب أن يكون ULID صالحًا.',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'اسم الوحدة',
            'parent_public_id' => 'الوحدة الأعلى',
        ];
    }
}
