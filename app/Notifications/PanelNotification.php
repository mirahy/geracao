<?php

namespace App\Notifications;

use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Aviso genérico entregue no painel (sino) e por e-mail. */
class PanelNotification extends Notification
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $url = null,
        public readonly string $actionLabel = 'Abrir',
        public readonly string $icon = 'heroicon-o-bell',
        public readonly string $color = 'primary',
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $notification = FilamentNotification::make()
            ->title($this->title)
            ->body($this->body)
            ->icon($this->icon)
            ->color($this->color);

        if ($this->url) {
            $notification->actions([Action::make('open')->label($this->actionLabel)->url($this->url)->markAsRead()]);
        }

        return $notification->getDatabaseMessage();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title)
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line($this->body);

        if ($this->url) {
            $mail->action($this->actionLabel, $this->url);
        }

        return $mail;
    }
}
