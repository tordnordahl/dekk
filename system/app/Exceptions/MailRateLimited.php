<?php
namespace App\Exceptions;
class MailRateLimited extends \RuntimeException
{
    public function __construct(public int $retryAfter = 600)
    {
        parent::__construct('Utsending venter på ledig kapasitet. Meldingen beholdes i køen.');
    }
}
