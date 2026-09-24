<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Eine Site ohne statamic-webhook-manager, in einem eigenen PHP-Prozess: in
 * dieser Suite ist der Webhook-Manager installiert und würde eine Klasse
 * verdecken, die ihn zu früh lädt (courses 0.2.0 stürzte so auf jeder Site
 * ohne private-media beim Booten ab).
 */
class BootWithoutWebhookManagerTest extends TestCase
{
    #[Test]
    public function it_boots_without_the_webhook_manager(): void
    {
        $process = new Process([PHP_BINARY, __DIR__.'/../Boot/boot-without-webhook-manager.php']);
        $process->setTimeout(120)->run();

        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertStringContainsString('booted', $output);
        $this->assertStringNotContainsString('not found', $output);
    }
}
