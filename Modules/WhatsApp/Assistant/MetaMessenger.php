<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Assistant;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Modules\WhatsApp\Models\WhatsAppConfiguration;
use Throwable;

/**
 * Replies to a staff member inside their open 24-hour window: free-form text
 * and PDF documents. No templates are needed, because staff always write
 * first.
 *
 * Sent SYNCHRONOUSLY, not through the queue — the queue only drains once a
 * minute on this host, which is far too slow for a chat. Never throws: a
 * failed send is logged and reported as false.
 */
final class MetaMessenger implements ReplySink
{
    use RecordsOutboundMessages;

    private const TIMEOUT_SECONDS = 15;

    public function __construct(private readonly HttpFactory $http)
    {
    }

    public function sendText(string $to, string $body, ?int $conversationId = null): bool
    {
        $sent = $this->post([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['preview_url' => true, 'body' => mb_substr($body, 0, 4000)],
        ]);

        $this->record($conversationId, 'text', $body);

        return $sent;
    }

    /**
     * Upload a PDF to Meta's media store, then send it as a document. The file
     * is never put on a public URL.
     */
    public function sendDocument(string $to, string $pdf, string $filename, string $caption, ?int $conversationId = null): bool
    {
        $config = WhatsAppConfiguration::current();

        try {
            $upload = $this->http->timeout(self::TIMEOUT_SECONDS)
                ->withToken((string) $config->access_token)
                ->attach('file', $pdf, $filename, ['Content-Type' => 'application/pdf'])
                ->post($this->base($config) . '/media', [
                    'messaging_product' => 'whatsapp',
                    'type' => 'application/pdf',
                ]);

            $mediaId = $upload->successful() ? (string) $upload->json('id', '') : '';
            if ($mediaId === '') {
                Log::warning('WhatsApp assistant: media upload failed', ['status' => $upload->status(), 'body' => $upload->body()]);
                $this->record($conversationId, 'document', '[failed] ' . $filename);

                return false;
            }
        } catch (Throwable $e) {
            Log::warning('WhatsApp assistant: media upload error', ['error' => $e->getMessage()]);

            return false;
        }

        $sent = $this->post([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'document',
            'document' => ['id' => $mediaId, 'filename' => $filename, 'caption' => $caption],
        ]);

        $this->record($conversationId, 'document', $filename);

        return $sent;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(array $payload): bool
    {
        $config = WhatsAppConfiguration::current();
        if ((string) $config->phone_number_id === '' || (string) $config->access_token === '') {
            Log::warning('WhatsApp assistant: Meta credentials are not set');

            return false;
        }

        try {
            $response = $this->http->timeout(self::TIMEOUT_SECONDS)
                ->withToken((string) $config->access_token)
                ->post($config->graphEndpoint(), $payload);

            if (! $response->successful()) {
                Log::warning('WhatsApp assistant: send failed', ['status' => $response->status(), 'body' => $response->body()]);
            }

            return $response->successful();
        } catch (Throwable $e) {
            Log::warning('WhatsApp assistant: send error', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function base(WhatsAppConfiguration $config): string
    {
        $version = (string) $config->api_version !== '' ? (string) $config->api_version : 'v21.0';

        return sprintf('https://graph.facebook.com/%s/%s', $version, (string) $config->phone_number_id);
    }

}
