<?php

return [

    'permission' => 'Manage offer settings',

    'groups' => [
        'seller' => [
            'title' => 'Seller',
            'description' => 'Who the withdrawal text names as the other party. With both fields empty the addon fills in the application name and the mail sender address.',
        ],
        'withdrawal' => [
            'title' => 'Withdrawal',
            'description' => 'The default an offer inherits while it says nothing of its own. An offer with its own text keeps it. Purchases already paid for keep the wording the buyer agreed to; it is frozen on the payment. Which fields the checkout asks the buyer for still lives in the file under "checkout_fields": a field library with a label, a type and rules per entry does not fit into a single form field.',
        ],
        'display' => [
            'title' => 'Display',
            'description' => 'What happens when a template renders an offer.',
        ],
    ],

    'fields' => [
        'seller_name' => [
            'label' => 'Name',
            'description' => 'Replaces {seller_name} everywhere in the withdrawal text. Empty means the application name.',
        ],
        'seller_contact' => [
            'label' => 'Contact',
            'description' => 'Where a withdrawal is sent, written into the text as {seller_contact}. Empty means the mail sender address.',
        ],
        'withdrawal_days' => [
            'label' => 'Withdrawal period in days',
            'description' => 'The period a new offer inherits. It also replaces {days} in the text.',
        ],
        'withdrawal_text' => [
            'label' => 'Withdrawal notice',
            'description' => 'The wording a buyer reads before paying, as long as the offer carries none of its own. A change applies from the next purchase; earlier ones keep their version.',
        ],
        'withdrawal_waiver_text' => [
            'label' => 'Waiver',
            'description' => 'The sentence a buyer of digital content agrees to before delivery (§ 356 (5) BGB). It is frozen on the payment, so a change never rewrites an earlier consent.',
        ],
        'withdrawal_b2b_text' => [
            'label' => 'Note for business buyers',
            'description' => 'Shown in addition when the buyer buys as a business. Empty means none is shown.',
        ],
        'withdrawal_checkbox_required' => [
            'label' => 'Require the tick',
            'description' => 'On: the checkout does not proceed without the tick. Off only makes sense when delivery happens after the period ends.',
        ],
        'count_impressions' => [
            'label' => 'Count impressions',
            'description' => 'On: every render through {{ offers:show }} raises the shown count. Off leaves it at zero, which makes the acceptance ratio meaningless — useful on heavily cached pages, where it says nothing anyway.',
        ],
    ],

];
