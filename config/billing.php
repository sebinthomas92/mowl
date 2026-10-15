<?php

/*
 * Plan and credit packs sold through Stripe. Prices are created inline at Checkout,
 * so Stripe needs no products set up in advance; STRIPE_PLAN_PRICE switches the plan
 * to an existing recurring Price instead.
 */
return [
    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'plan_price' => env('STRIPE_PLAN_PRICE'),
        'portal_configuration' => env('STRIPE_PORTAL_CONFIGURATION'),
        // Seconds a signed webhook stays valid, against replays.
        'tolerance' => 300,
    ],

    'currency' => 'usd',

    'plan' => [
        'name' => 'Marketing Owl Beta',
        'amount' => (int) env('BILLING_PLAN_AMOUNT', 12900),
        'interval' => 'month',
        // Granted once per paid billing cycle; unused credits carry over.
        'credits' => (int) env('CAMPAIGN_MONTHLY_CREDITS', 50),
    ],

    // One-time top-ups at the $2 overage rate. Only subscribed workspaces can buy them.
    'credit_packs' => [
        'credits_10' => ['credits' => 10, 'amount' => 2000],
        'credits_25' => ['credits' => 25, 'amount' => 5000],
        'credits_50' => ['credits' => 50, 'amount' => 10000],
    ],
];
