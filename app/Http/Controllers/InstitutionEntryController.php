<?php

namespace App\Http\Controllers;

use App\Actions\Identity\InstitutionEntry;
use App\Actions\Identity\InstitutionRecovery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class InstitutionEntryController extends Controller
{
    public function form()
    {
        return view('institution.entry');
    }

    public function login(Request $request, InstitutionEntry $entry)
    {
        $data = $this->validated($request, $this->identityRules() + ['password' => 'required|string|max:1024'], '/institution/login');
        $organization = $entry->login($request, $data['organization'], $data['email'], $data['password']);

        return redirect(route('institution.account', ['organization' => $organization], false));
    }

    public function account()
    {
        return view('institution.account', ['account' => Auth::guard('institution')->user()]);
    }

    public function logout(Request $request, InstitutionEntry $entry)
    {
        $entry->logout($request);

        return redirect('/institution/login');
    }

    public function recovery(Request $request, InstitutionRecovery $recovery)
    {
        $data = $this->validated($request, $this->identityRules(), '/institution/login');
        $recovery->request($data['organization'], $data['email'], (string) $request->ip());

        return redirect('/institution/login')->with('status', 'إذا كانت البيانات مطابقة لحساب متاح، فستصل تعليمات الاستعادة إلى البريد المسجل.');
    }

    public function resetForm(Request $request, string $organization, string $account, string $token)
    {
        abort_unless($request->hasValidSignature(false), 403, 'رابط الاستعادة غير صالح أو انتهت صلاحيته.');

        return view('institution.reset');
    }

    public function reset(Request $request, string $organization, string $account, string $token, InstitutionRecovery $recovery)
    {
        abort_unless($request->hasValidSignature(false), 403, 'رابط الاستعادة غير صالح أو انتهت صلاحيته.');
        $data = $this->validated($request, ['password' => 'required|string|min:12|max:128|confirmed'], $request->getRequestUri());
        if (! $recovery->reset($organization, $account, $token, $data['password'])) {
            return redirect($request->getRequestUri())->withErrors(['password' => 'رابط الاستعادة غير صالح أو انتهت صلاحيته.']);
        }

        return redirect('/institution/login')->with('status', 'تم تغيير كلمة المرور. سجّل الدخول من جديد إلى المؤسسة المحددة.');
    }

    private function validated(Request $request, array $rules, string $redirect): array
    {
        $validator = Validator::make($request->all(), $rules, $this->messages());
        if ($validator->fails()) {
            throw (new ValidationException($validator))->redirectTo($redirect);
        }

        return $validator->validated();
    }

    private function identityRules(): array
    {
        return ['organization' => 'required|string|max:128', 'email' => 'required|string|max:254'];
    }

    private function messages(): array
    {
        return ['required' => 'هذا الحقل مطلوب.', 'string' => 'أدخل نصًا صالحًا.',
            'max' => 'القيمة أطول من الحد المسموح.', 'min' => 'يجب ألا تقل كلمة المرور عن 12 محرفًا.',
            'confirmed' => 'تأكيد كلمة المرور غير مطابق.'];
    }
}
