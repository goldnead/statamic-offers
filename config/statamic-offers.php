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

    /*
    |--------------------------------------------------------------------------
    | Seller
    |--------------------------------------------------------------------------
    |
    | Who the buyer withdraws *from*. Written into the withdrawal text below
    | wherever `{seller_name}` and `{seller_contact}` appear. Left null, the
    | application name and the mail sender address stand in — which is right
    | for a one-person site and wrong the moment a legal entity sells here, so
    | fill it in.
    |
    */

    'seller' => [
        'name' => null,
        'contact' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Withdrawal (Widerruf)
    |--------------------------------------------------------------------------
    |
    | The site-wide default an offer inherits unless it sets its own. Every key
    | may be overridden per offer in the Control Panel; an empty field there
    | means "this".
    |
    | **Entwurf, anwaltlich prüfen.** The wording below follows § 355, § 356
    | Abs. 5 and § 356a BGB and the Muster-Widerrufsbelehrung as of 2026, but
    | no text shipped in a config file is legal advice. Have it checked before
    | the first sale.
    |
    | Placeholders: `{days}`, `{seller_name}`, `{seller_contact}`.
    |
    | `waiver_text` is the sentence a buyer of digital content agrees to before
    | the content is delivered (§ 356 Abs. 5 BGB). The exact wording a buyer
    | agreed to is frozen on the payment, together with the version hash
    | `Offer::withdrawalTerms()` computes — so that changing this text later
    | never rewrites what an earlier buyer consented to.
    |
    */

    'withdrawal' => [
        'days' => 14,

        'text' => "Widerrufsrecht\n\n"
            .'Sie haben das Recht, diesen Vertrag binnen {days} Tagen ohne Angabe von Gründen zu widerrufen. '
            ."Die Widerrufsfrist beträgt {days} Tage ab dem Tag des Vertragsschlusses.\n\n"
            .'Um Ihr Widerrufsrecht auszuüben, müssen Sie uns ({seller_name}, {seller_contact}) mittels einer eindeutigen Erklärung '
            .'(z. B. per E-Mail oder Brief) über Ihren Entschluss informieren, diesen Vertrag zu widerrufen. '
            .'Sie können dafür das Muster-Widerrufsformular verwenden, das ist jedoch nicht vorgeschrieben. '
            ."Sie können den Vertrag auch über die Schaltfläche „Vertrag widerrufen“ auf unserer Website widerrufen.\n\n"
            ."Zur Wahrung der Widerrufsfrist reicht es aus, dass Sie die Erklärung über die Ausübung des Widerrufsrechts vor Ablauf der Widerrufsfrist absenden.\n\n"
            ."Folgen des Widerrufs\n\n"
            .'Wenn Sie diesen Vertrag widerrufen, erstatten wir Ihnen alle Zahlungen, die wir von Ihnen erhalten haben, '
            .'unverzüglich und spätestens binnen vierzehn Tagen ab dem Tag, an dem die Mitteilung über Ihren Widerruf bei uns eingegangen ist. '
            .'Für die Rückzahlung verwenden wir dasselbe Zahlungsmittel, das Sie bei der ursprünglichen Transaktion eingesetzt haben, '
            .'sofern mit Ihnen nicht ausdrücklich etwas anderes vereinbart wurde.',

        // § 356 Abs. 5 BGB. Entwurf, anwaltlich prüfen.
        'waiver_text' => 'Ich verlange ausdrücklich, dass mit der Ausführung vor Ablauf der Widerrufsfrist begonnen wird, '
            .'und weiß, dass ich mit Beginn der Ausführung mein Widerrufsrecht verliere.',

        // Whether the checkout may not proceed until the waiver is ticked. Off
        // only makes sense for a product that is not delivered before the
        // period ends.
        'checkbox_required' => true,

        // A separate note for business buyers, who have no statutory right of
        // withdrawal. Null means none is shown.
        'b2b_text' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Checkout fields — the library
    |--------------------------------------------------------------------------
    |
    | Every field a checkout *could* ask for, defined once. An offer then picks
    | from this list (`checkout_fields` on the offer); a funnel step that finds
    | no pick on the offer falls back to its own configuration.
    |
    | Add your own entries freely. Shape per entry:
    |
    |   'key' => [
    |       'label'    => 'Shown to the buyer', // a translation key works too
    |       'type'     => 'text',              // text | select | checkbox
    |       'required' => false,
    |       'options'  => ['a' => 'A'],         // select only
    |       'rules'    => ['size:2'],           // extra Laravel rules
    |   ],
    |
    | `country` is a two-letter ISO code as free text rather than a select,
    | because that is what the funnel's checkout already validates (`size:2`)
    | and what the invoice needs.
    |
    | Postal address is mandatory on an invoice over 250 € (§ 14 UStG), which
    | is why the first five are in the library by default.
    |
    */

    'checkout_fields' => [
        'name' => ['label' => 'statamic-offers::messages.checkout_field_name', 'type' => 'text', 'required' => true],
        'street' => ['label' => 'statamic-offers::messages.checkout_field_street', 'type' => 'text', 'required' => true],
        'postal_code' => ['label' => 'statamic-offers::messages.checkout_field_postal_code', 'type' => 'text', 'required' => true, 'rules' => ['max:16']],
        'city' => ['label' => 'statamic-offers::messages.checkout_field_city', 'type' => 'text', 'required' => true],
        'country' => ['label' => 'statamic-offers::messages.checkout_field_country', 'type' => 'text', 'required' => true, 'rules' => ['size:2']],
        'phone' => ['label' => 'statamic-offers::messages.checkout_field_phone', 'type' => 'text', 'required' => false],
        'company' => ['label' => 'statamic-offers::messages.checkout_field_company', 'type' => 'text', 'required' => false],
        'vat_id' => ['label' => 'statamic-offers::messages.checkout_field_vat_id', 'type' => 'text', 'required' => false, 'rules' => ['max:20']],
    ],
];
