<?php
namespace App\Mail;
use Illuminate\Bus\Queueable;use Illuminate\Mail\Mailable;use Illuminate\Queue\SerializesModels;
class OutboundMail extends Mailable {use Queueable,SerializesModels;public function __construct(public string $mailSubject,public string $mailBody){}public function build():self{$mail=$this->subject($this->mailSubject)->view('emails.outbound');$reply=config('mail.reply_to.address');return $reply?$mail->replyTo($reply):$mail;}}
