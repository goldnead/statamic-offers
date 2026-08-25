<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Handle prefix
    |--------------------------------------------------------------------------
    |
    | How an offer is referred to where a product handle is expected. With the
    | default, `offer:fruehling-upsell` buys the offer `fruehling-upsell`.
    |
    | The prefix exists so an offer and a product can never be mistaken for one
    | another: a product is what a thing costs, an offer is what it costs
    | *here*, and a checkout that confused them would charge the wrong price.
    |
    */

    'handle_prefix' => 'offer:',

    /*
    |--------------------------------------------------------------------------
    | Counting
    |--------------------------------------------------------------------------
    |
    | Whether `{{ offers:show }}` counts an impression. Off means the accepted
    | count still rises but the shown count stays at zero, so the ratio becomes
    | meaningless — that is the trade, and it is here because a heavily cached
    | page counts nothing useful anyway.
    |
    */

    'count_impressions' => true,
];
