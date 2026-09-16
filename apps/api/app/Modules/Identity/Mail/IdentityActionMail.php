<?php

declare(strict_types=1);

namespace App\Modules\Identity\Mail;

use Illuminate\Mail\Mailable;

final class IdentityActionMail extends Mailable
{
    public function __construct(public string $actionUrl, public string $purpose, public string $code) {}

    public function build(): self
    {
        $title = $this->purpose === 'verify_email' ? 'Подтверждение адреса KIRH GEO' : 'Смена пароля KIRH GEO';

        return $this->subject($title)->html('<p>'.e($title).'</p><p>Проверочный код: <strong>'.e($this->code).'</strong></p><p><a href="'.e($this->actionUrl).'">Продолжить в браузере</a></p><p>Если вы не запрашивали это действие, проигнорируйте письмо.</p>');
    }
}
