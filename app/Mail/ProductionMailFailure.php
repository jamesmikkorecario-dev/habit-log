<?php

namespace App\Mail;

use Symfony\Component\Mailer\Exception\TransportException;

class ProductionMailFailure extends TransportException
{
    public function __construct()
    {
        // Never retain the transport exception, message, URL or credentials.
        parent::__construct('Security email is temporarily unavailable. Please try again later.');
    }
}
