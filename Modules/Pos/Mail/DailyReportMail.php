<?php

declare(strict_types=1);

namespace Modules\Pos\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * The automated daily sales + stock report email, with the professional PDF
 * attached. Built and dispatched by {@see \Modules\Pos\Services\DailyReport}.
 */
final class DailyReportMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $data  Full report payload for the email body.
     * @param  string  $pdf  Rendered PDF bytes, attached to the message.
     */
    public function __construct(
        public array $data,
        public string $pdf,
        public string $venue,
        public Carbon $periodEnd,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->venue . ' — Daily Report — ' . $this->periodEnd->isoFormat('MMM D, YYYY'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'pos::daily-report-email',
            with: ['data' => $this->data],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => $this->pdf, 'daily-report-' . $this->periodEnd->format('Y-m-d') . '.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
