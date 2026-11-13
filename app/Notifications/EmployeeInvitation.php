<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class EmployeeInvitation extends ResetPassword
{
    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject('Zaproszenie do zespołu ActiveFit')
            ->greeting('Dzień dobry!')
            ->line('Administrator utworzył dla Ciebie konto zespołu ActiveFit. Ustaw własne hasło, aby rozpocząć pracę.')
            ->action('Ustaw hasło', $url)
            ->line('Link jest jednorazowy i wygasa po '.config('auth.passwords.'.config('auth.defaults.passwords').'.expire').' minutach.')
            ->line('Jeśli nie oczekujesz tego zaproszenia, skontaktuj się z klubem.');
    }
}
