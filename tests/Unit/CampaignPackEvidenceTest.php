<?php

namespace Tests\Unit;

use App\Models\SourceSnapshot;
use App\Services\CampaignPackEvidence;
use Tests\TestCase;

class CampaignPackEvidenceTest extends TestCase
{
    private const URL = 'https://theplushrepublic.com/product/kindle-stand/';

    public function test_excerpts_match_despite_retyped_dashes_quotes_spacing_and_url_form(): void
    {
        $result = $this->check([
            $this->record('e1', 'The stand is book-shaped', 'Kindle Stand - Book-Shaped Holder', 'https://www.theplushrepublic.com/product/kindle-stand'),
            $this->record('e2', 'It fits most e-readers', 'Suitable for most common e-readers with "slim cases"'),
            $this->record('e3', 'It costs ₹699', '₹750₹699 Inclusive of all taxes'),
        ]);

        $this->assertIsArray($result);
        [$generated, $evidence] = $result;
        $this->assertCount(3, $evidence);
        $this->assertCount(2, $generated['product_truth']['verified_facts']);
    }

    public function test_an_untraceable_record_and_its_verified_fact_are_dropped_and_the_rest_kept(): void
    {
        $result = $this->check([
            $this->record('e1', 'The stand is book-shaped', 'Kindle Stand – Book-Shaped Holder'),
            $this->record('e2', 'It fits most e-readers', 'Fits every e-reader ever made'),
        ]);

        [$generated, $evidence] = $result;
        $this->assertSame(['e1'], array_column($evidence, 'id'));
        $this->assertSame(['e1'], array_column($generated['product_truth']['verified_facts'], 'claim_id'));
    }

    public function test_a_pack_with_no_traceable_evidence_or_an_unflagged_unsafe_claim_is_rejected(): void
    {
        $this->assertSame('no_traceable_evidence', $this->check([
            $this->record('e1', 'The stand is book-shaped', 'Not on the page at all'),
        ]));

        $this->assertSame('unflagged_claim', $this->check([
            $this->record('e1', 'The stand is book-shaped', 'Kindle Stand – Book-Shaped Holder'),
            ['id' => 'e2', 'claim' => 'Handmade in India', 'source' => self::URL, 'excerpt' => '', 'status' => 'too_specific_for_evidence'],
        ]));
    }

    public function test_team_confirmed_facts_and_flagged_unsafe_claims_are_kept(): void
    {
        $result = $this->check([
            ['id' => 'e1', 'claim' => 'Made for bedside reading', 'source' => 'team_confirmed', 'excerpt' => 'bedside readers', 'status' => 'directly_supported'],
            ['id' => 'e2', 'claim' => 'Handmade in India', 'source' => self::URL, 'excerpt' => '', 'status' => 'too_specific_for_evidence'],
        ], [['severity' => 'warning', 'claim' => 'Handmade in India', 'reason' => 'The page only says made, sourced or packed in India.']]);

        $this->assertSame(['e1', 'e2'], array_column($result[1], 'id'));
    }

    private function check(array $evidence, array $flags = []): array|string
    {
        $generated = ['product_truth' => ['verified_facts' => [
            ['statement' => 'The stand is book-shaped', 'claim_id' => 'e1'],
            ['statement' => 'It fits most e-readers', 'claim_id' => 'e2'],
        ]]];

        return app(CampaignPackEvidence::class)->check($generated, $evidence, $flags, new SourceSnapshot(['url' => self::URL]), [
            'url' => self::URL,
            'description' => 'A minimal e-reader holder.',
            'content' => "Kindle Stand – Book-Shaped Holder.\u{00A0}Suitable for most common e-readers with \u{201C}slim cases\u{201D} or grips. ₹750₹699 Inclusive of all taxes",
            'product_truth' => ['name' => 'Kindle Stand – Book-Shaped Holder', 'price' => '699.00'],
            'team_knowledge' => ['audience' => 'Bedside readers'],
        ]);
    }

    private function record(string $id, string $claim, string $excerpt, string $source = self::URL): array
    {
        return ['id' => $id, 'claim' => $claim, 'source' => $source, 'excerpt' => $excerpt, 'status' => 'directly_supported'];
    }
}
