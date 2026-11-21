<?php

declare(strict_types=1);

namespace App\Erp\Abdullah;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * An email from Abdullah to staff: his words, then the exact list the ERP
 * found (trip, time, what is missing, a link to open it).
 */
final class AbdullahMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<array{reference: string, time: string, missing: string, url: string}>  $rows
     */
    public function __construct(
        public string $subjectLine,
        public string $text,
        public array $rows = [],
        public string $language = 'ar',
    ) {
        $this->locale($language === 'ar' ? 'ar' : 'en');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.abdullah', with: [
            'text' => $this->text,
            'rows' => $this->rows,
            'rtl' => $this->language === 'ar',
        ]);
    }
}
