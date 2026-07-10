<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SourceSnapshot;

class MockCampaignPackGenerator
{
    public function generate(Product $product, SourceSnapshot $source): array
    {
        $name = $product->name;

        return [
            'product_truth' => [
                'name' => $name,
                'price' => $product->price ?: 'Price not supplied',
                'source' => $source->url,
                'verified_facts' => ['Designed for everyday use', 'Product details anchored to the supplied page'],
            ],
            'direction' => [
                'title' => "Make {$name} the easy choice.",
                'summary' => "Position {$name} as a considered everyday upgrade: useful, easy to understand, and ready to fit into the customer’s routine.",
            ],
            'audiences' => ['Intent-led shoppers comparing practical options', 'Existing customers ready for a thoughtful upgrade'],
            'benefits' => ['Simple to understand at a glance', 'Built around a real everyday need', 'Easy to demonstrate in short-form creative'],
            'meta' => [
                'primary_text' => "Meet {$name} — the practical upgrade that makes the everyday feel more considered. See the details, picture it in your routine, and choose with confidence.",
                'headlines' => ["Meet {$name}", 'A smarter everyday upgrade', 'Made to fit your routine'],
                'descriptions' => ['Explore the product details.', 'A practical choice, clearly explained.'],
            ],
            'hooks' => ['The upgrade you’ll notice every day.', 'Before you settle for the usual, see this.', 'One small switch. A much better routine.'],
            'script' => [
                ['time' => '0:00 – 0:03', 'line' => 'What if the everyday option felt a little more considered?'],
                ['time' => '0:03 – 0:07', 'line' => "Meet {$name}."],
                ['time' => '0:07 – 0:12', 'line' => 'Useful, easy to understand, and designed for real routines.'],
                ['time' => '0:12 – 0:16', 'line' => 'See the details that make the difference.'],
                ['time' => '0:16 – 0:20', 'line' => "{$name}. Make the easy choice."],
            ],
            'captions' => ['A practical upgrade for the everyday. ✦', "Meet {$name}. Details in the link."],
            'shot_log' => ['0–3s: Problem-led opening detail', '3–8s: Clean product reveal', '8–14s: Product in an everyday setting', '14–20s: Detail close-up and CTA'],
        ];
    }
}
