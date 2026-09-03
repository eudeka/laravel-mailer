<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class WelcomeUserMailable extends Mailable
{
    public function build(): self
    {
        return $this->subject('Welcome to Laravel')
            ->html('<h1>Welcome aboard!</h1>')
            ->attachData('PDF content', 'welcome.pdf', ['mime' => 'application/pdf']);
    }
}

it('sends mailable through Laravel MailManager using multi-vendor transport', function () {
    config()->set('mailer.priority', ['resend']);
    config()->set('mailer.providers.resend.api_key', 're_laravel_transport_test');

    config()->set('mail.mailers.multi-vendor', [
        'transport' => 'multi-vendor',
    ]);
    config()->set('mail.default', 'multi-vendor');
    config()->set('mail.from.address', 'noreply@myapp.com');
    config()->set('mail.from.name', 'My App');

    Http::fake([
        'https://api.resend.com/emails' => Http::response(['id' => 'resend_transport_001'], 200),
    ]);

    Mail::to('user@example.com')->send(new WelcomeUserMailable);

    Http::assertSent(function (Request $request) {
        $data = $request->data();

        return $request->url() === 'https://api.resend.com/emails'
            && $data['from'] === 'My App <noreply@myapp.com>'
            && $data['to'] === ['user@example.com']
            && $data['subject'] === 'Welcome to Laravel'
            && str_contains($data['html'], 'Welcome aboard!')
            && count($data['attachments']) === 1
            && $data['attachments'][0]['filename'] === 'welcome.pdf';
    });
});

it('supports mailer transport driver alias', function () {
    config()->set('mailer.priority', ['resend']);
    config()->set('mailer.providers.resend.api_key', 're_laravel_transport_test');

    config()->set('mail.mailers.mailer', [
        'transport' => 'mailer',
    ]);
    config()->set('mail.default', 'mailer');
    config()->set('mail.from.address', 'noreply@myapp.com');
    config()->set('mail.from.name', 'My App');

    Http::fake([
        'https://api.resend.com/emails' => Http::response(['id' => 'resend_transport_alias_001'], 200),
    ]);

    Mail::to('alias@example.com')->send(new WelcomeUserMailable);

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.resend.com/emails'
            && $request->data()['to'] === ['alias@example.com'];
    });
});
