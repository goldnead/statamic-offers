<?php

use Goldnead\StatamicOffers\Events\OfferSoldOut;
use Goldnead\StatamicOffers\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\ServiceProvider;
use Orchestra\Testbench\Foundation\Application;

/*
 * Boots the addon the way a site without statamic-webhook-manager does. Run
 * in its own PHP process by BootWithoutWebhookManagerTest: the webhook
 * manager is a dev dependency here, so its namespace is taken out of the
 * autoloader, which is exactly what an install without it sees.
 *
 * Prints "booted" on success; a fatal error ends the process with its message.
 */

$loader = require __DIR__.'/../../vendor/autoload.php';
$loader->setPsr4('Goldnead\\WebhookManager\\', []);

foreach ([
    'Goldnead\\WebhookManager\\Facades\\WebhookManager',
    'Goldnead\\WebhookManager\\Contracts\\TriggerInterface',
] as $sibling) {
    if (class_exists($sibling) || interface_exists($sibling)) {
        fwrite(STDERR, "precondition: {$sibling} is installed, this check proves nothing\n");
        exit(2);
    }
}

$app = Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
$app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

// register() and the bridge's booted callback: the two places the optional
// coupling is decided. Statamic's own boot needs a whole site and is not what
// is checked here.
$app->register(ServiceProvider::class);
$app->make(WebhookManagerBridge::class)->boot($app['events']);
$app['events']->dispatch(new OfferSoldOut(new Offer(['brand_id' => 0]), 1));

echo "booted\n";
