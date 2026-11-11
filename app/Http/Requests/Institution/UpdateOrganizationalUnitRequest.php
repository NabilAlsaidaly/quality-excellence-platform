<?php

declare(strict_types=1);

namespace App\Http\Requests\Institution;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateOrganizationalUnitRequest extends FormRequest
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
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowedFields = [
                'name',
                '_token',
                '_method',
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
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'اسم الوحدة',
        ];
    }
}
