<?php

namespace App\Mail;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

class PrivateTransport implements TransportInterface
{
    public function __construct(private TransportInterface $transport) {}

    public function send(#[\SensitiveParameter] RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        try {
            return $this->transport->send($message, $envelope);
        } catch (\Throwable) {
            throw new ProductionMailFailure;
        }
    }

    public function __toString(): string
    {
        return 'production-mail';
    }
}
