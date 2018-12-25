<?php

namespace App\Notifications;

use App\Channels\SurftechChannel;
use App\Notifications\Messages\SendSMS;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ParticipantSms extends Notification implements ShouldQueue
{
    use Queueable;

    public $message;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($message = '')
    {
        $this->message = $message;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return [SurftechChannel::class];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \App\Notifications\Messages\SendSMS
     */
    public function toSurftech($notifiable)
    {
        return (new SendSMS)
            ->authDetails()
            ->setType()
            ->batchType()
            ->sourceAddress()
            ->payload($this->message)
            ->destinationAddress($notifiable);
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            //
        ];
    }
}
