<?php
namespace App\Mail;

use App\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class QuoteMail extends Mailable
{
    use Queueable, SerializesModels;
    public function __construct(public Quote $quote, public string $responseUrl) {}
    public function build(): self { $mail=$this->subject('Dekkanbefaling '.$this->quote->reference)->view('emails.quote');$reply=config('mail.reply_to.address');return $reply?$mail->replyTo($reply):$mail; }
}
