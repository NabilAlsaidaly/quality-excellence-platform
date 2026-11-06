<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

final class InstitutionPasswordReset extends Notification
{
    public function __construct(public string $organization, public string $account, #[\SensitiveParameter] public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('استعادة الوصول إلى الحساب المؤسسي')
            ->greeting('مرحبًا')->line('هذا الرابط خاص بالحساب المؤسسي المحدد فقط.')
            ->action('تعيين كلمة مرور جديدة', $this->url())
            ->line('إذا لم تطلب استعادة الوصول، فتجاهل هذه الرسالة.');
    }

    public function url(): string
    {
        // Mail origin comes only from trusted configuration, never Host headers.
        $path = URL::temporarySignedRoute('institution.reset', now()->addMinutes(config('auth.passwords.institution.expire')),
            ['organization' => $this->organization, 'account' => $this->account, 'token' => $this->token], absolute: false);

        return rtrim(config('app.url'), '/').$path;
    }
}
