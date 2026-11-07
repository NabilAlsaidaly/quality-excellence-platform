<?php

namespace App\Http\Requests\Institution;

use App\Http\Controllers\Institution\ApproachController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Inert until explicitly registered behind InstitutionSession and an approved Policy. */
final class StoreApproachContentRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        ApproachController::authorizeRequest($this, 'editDraft');

        return true;
    }

    public function rules(): array
    {
        return ['body' => 'required|string|max:1000000'];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->except('_token', '_method')), array_keys($this->rules())) !== []) {
                $validator->errors()->add('approach', __('institution_approaches.unknown_fields', [], 'ar'));
            }
        }];
    }

    public function messages(): array
    {
        return ['required' => 'هذا الحقل مطلوب.', 'string' => 'أدخل نصًا صالحًا.', 'max' => 'تجاوز النص الحد التقني المسموح.',
            'ulid' => 'المعرّف غير صالح.', 'regex' => 'أعد فتح النموذج قبل الحفظ.', 'accepted' => 'يجب تأكيد نقل الملكية.'];
    }

    protected function getRedirectUrl(): string
    {
        return ApproachController::destination($this, 'content', true);
    }
}
