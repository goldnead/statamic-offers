/**
 * Control Panel entry. The registered name must match what the controller
 * passes to `Inertia::render()`, exactly.
 */

import OffersIndex from './pages/Offers/Index.vue';
import CouponsIndex from './pages/Coupons/Index.vue';
import SetupRequired from './pages/SetupRequired.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('statamic-offers::Offers/Index', OffersIndex);
    Statamic.$inertia.register('statamic-offers::Coupons/Index', CouponsIndex);
    Statamic.$inertia.register('statamic-offers::SetupRequired', SetupRequired);
});
