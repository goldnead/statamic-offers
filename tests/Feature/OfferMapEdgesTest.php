<?php

namespace Goldnead\StatamicOffers\Tests\Feature;

use Goldnead\BrandContext\Map\MapEdge;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\StatamicOffers\Integrations\Map\OfferMapObjects;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Die Kanten Angebot → Produkt für die Funnel-Karte.
 *
 * Eine Kante entsteht nur, wenn das Angebot das Produkt tatsächlich nennt.
 * Nichts wird geschätzt.
 */
class OfferMapEdgesTest extends TestCase
{
    protected OfferMapObjects $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new OfferMapObjects;
    }

    protected function offer(array $overrides = []): Offer
    {
        return Offer::create(array_merge([
            'handle' => 'stimmbildung',
            'name' => 'Stimmbildung im Chor',
            'product' => 'noten-paket',
            'amount_cent' => 39000,
            'slot' => Offer::SLOT_STANDALONE,
            'active' => true,
        ], $overrides));
    }

    #[Test]
    public function an_offer_with_two_products_gives_one_edge_per_product(): void
    {
        $this->offer(['products' => ['probe-zugang']]);

        $edges = iterator_to_array($this->provider->edges(), false);

        $this->assertCount(2, $edges);

        $this->assertContainsOnlyInstancesOf(MapEdge::class, $edges);

        $this->assertSame('offer', $edges[0]->fromType);
        $this->assertSame('stimmbildung', $edges[0]->fromId);
        $this->assertSame('product', $edges[0]->toType);
        $this->assertSame('noten-paket', $edges[0]->toId);
        $this->assertSame('sells', $edges[0]->kind);
        $this->assertSame('sells', $edges[0]->label);
        $this->assertSame('products of the offer', $edges[0]->derivedFrom);
        $this->assertFalse($edges[0]->dashed);

        $this->assertSame('offer', $edges[1]->fromType);
        $this->assertSame('stimmbildung', $edges[1]->fromId);
        $this->assertSame('product', $edges[1]->toType);
        $this->assertSame('probe-zugang', $edges[1]->toId);
        $this->assertSame('sells', $edges[1]->kind);
    }

    #[Test]
    public function an_offer_without_products_gives_no_edge(): void
    {
        $this->offer(['product' => '', 'products' => []]);

        $this->assertSame([], iterator_to_array($this->provider->edges(), false));
    }

    #[Test]
    public function the_registry_lists_the_offer_edges(): void
    {
        $this->offer(['products' => ['probe-zugang']]);

        $edges = app(MapObjectRegistry::class)->edges();

        $this->assertArrayHasKey('offer:stimmbildung>sells>product:noten-paket', $edges);
        $this->assertArrayHasKey('offer:stimmbildung>sells>product:probe-zugang', $edges);
        $this->assertSame('noten-paket', $edges['offer:stimmbildung>sells>product:noten-paket']->toId);
    }
}
