<?php

declare(strict_types=1);

namespace Tests\Feature;

use Modules\Pos\Models\PosOrder;
use Modules\Pos\Services\PosReceiptImageRenderer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The receipt PNG lives on the PUBLIC disk (Meta must fetch it without auth),
 * so its filename must not be guessable. Before this, the name was derived
 * only from the order reference + sequential id — `POS-1-0004-4.png` — which
 * an unauthenticated attacker could enumerate to harvest every customer's
 * phone number and order details. A random token now defeats that.
 *
 * safeFilename() is private and needs no DB, so this is a plain unit test
 * (no migrations / no Imagick).
 */
final class PosReceiptFilenameTest extends TestCase
{
    private function filenameFor(PosOrder $order): string
    {
        $method = new ReflectionMethod(PosReceiptImageRenderer::class, 'safeFilename');
        $method->setAccessible(true);

        return (string) $method->invoke(new PosReceiptImageRenderer(), $order);
    }

    private function order(): PosOrder
    {
        $order = new PosOrder();
        $order->forceFill(['id' => 4, 'reference' => 'POS/1/0004']);

        return $order;
    }

    public function test_the_filename_keeps_a_recognisable_prefix_but_appends_a_random_token(): void
    {
        $name = $this->filenameFor($this->order());

        // Human-readable prefix is preserved for operators browsing the bucket,
        // and `/` from the reference is sanitised so the write can't break.
        $this->assertStringStartsWith('POS-1-0004-4-', $name);
        $this->assertStringNotContainsString('/', $name);
        // …but a long unguessable suffix follows the id.
        $this->assertMatchesRegularExpression('/^POS-1-0004-4-[A-Za-z0-9]{32}$/', $name);
    }

    public function test_two_renders_of_the_same_order_are_not_predictable(): void
    {
        $order = $this->order();

        $this->assertNotSame(
            $this->filenameFor($order),
            $this->filenameFor($order),
            'The receipt filename must include fresh randomness so the public URL cannot be enumerated.',
        );
    }
}
