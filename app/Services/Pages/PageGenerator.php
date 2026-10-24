<?php

namespace App\Services\Pages;

use App\Models\Page;
use App\Models\PageGeneration;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Pages\Document\ProductBrief;
use App\Services\Pages\Writing\PageCopy;
use App\Services\Pages\Writing\PageWriters;
use App\Services\ProviderCostCalculator;
use App\Support\GenerationDeadline;
use App\Support\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * AI writing on a page: the whole page from the product brief, or one section again with the team's
 * direction. Each run is recorded with what it cost. The first whole-page write of a page is included;
 * later ones cost a credit, returned if the run fails. Section rewrites are included.
 *
 * The run happens inside the request. The editor waits for it with the page locked, so nothing the
 * person types can be overwritten by the AI's answer.
 */
class PageGenerator
{
    /** Seconds the AI may take, inside the function's 300 second limit. */
    public const DEADLINE_SECONDS = 240;

    /** A run still "running" after this many minutes was cut off and is settled as failed. */
    public const STALE_MINUTES = 6;

    public function __construct(private PageBuilder $builder, private PageWriters $writers, private ProviderCostCalculator $costs) {}

    /** What the next whole-page write costs, in credits. */
    public function pageCost(Page $page): int
    {
        return $page->generations()->where('kind', 'page')->where('status', 'completed')->exists() ? 1 : 0;
    }

    /**
     * Writes the page ($sectionId null) or one section. $baseRevision is the revision the editor
     * shows, so a run never starts from a page the person has not seen.
     *
     * @return array{revision: int, document: array, generation: PageGeneration}
     */
    public function write(Page $page, User $actor, ?string $sectionId, string $instruction, int $baseRevision): array
    {
        if (! Membership::of($actor, $page->workspace_id)?->canCreate()) {
            throw ValidationException::withMessages(['ai' => 'Your role cannot change this page.']);
        }
        $instruction = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $instruction)), 0, 600);
        $kind = $sectionId === null ? 'page' : 'section';
        $limiter = 'page-writing:'.$page->workspace_id;
        if (RateLimiter::tooManyAttempts($limiter, max(1, (int) config('pages.ai_runs_per_hour')))) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($limiter) / 60));
            throw ValidationException::withMessages(['ai' => "The AI has written a lot for this workspace in the last hour. Try again in {$minutes} ".($minutes === 1 ? 'minute.' : 'minutes.')]);
        }

        [$generation, $document, $targets] = DB::transaction(function () use ($page, $actor, $sectionId, $instruction, $kind, $baseRevision): array {
            $workspace = Workspace::query()->lockForUpdate()->findOrFail($page->workspace_id);
            $locked = Page::query()->lockForUpdate()->findOrFail($page->id);
            if ($locked->publish_state === 'publishing') {
                throw ValidationException::withMessages(['ai' => 'Wait for publishing to finish before the AI writes.']);
            }
            if (! $locked->hasDocument()) {
                throw ValidationException::withMessages(['ai' => 'Open this page in the editor first.']);
            }
            if ($locked->document_revision !== $baseRevision) {
                throw ValidationException::withMessages(['revision' => 'This page changed in another tab or by the AI. Reload to get the latest version.']);
            }
            $this->settleStale($locked);
            if ($locked->generations()->where('status', 'running')->exists()) {
                throw ValidationException::withMessages(['ai' => 'The AI is already writing this page. Wait for it to finish.']);
            }
            if ($workspace->billingLocked()) {
                throw ValidationException::withMessages(['ai' => Workspace::BILLING_LOCKED_MESSAGE]);
            }
            $document = (array) $locked->document;
            $targets = $kind === 'page' ? PageCopy::targets($document) : $this->sectionTarget($document, (string) $sectionId);
            if ($targets === []) {
                throw ValidationException::withMessages(['ai' => 'There is nothing on this page for the AI to write yet. Add a section first.']);
            }
            $cost = $kind === 'page' ? $this->pageCost($locked) : 0;
            if ($cost > 0 && $workspace->creditBalance() < $cost) {
                throw ValidationException::withMessages(['ai' => 'This workspace has no credits left for another AI page. The first AI write of each page is included; buy credits on the Billing page.']);
            }
            $generation = PageGeneration::create([
                'workspace_id' => $workspace->id, 'page_id' => $locked->id, 'user_id' => $actor->id, 'kind' => $kind, 'status' => 'running',
                'instruction' => $instruction !== '' ? $instruction : null, 'target' => $sectionId, 'base_revision' => $locked->document_revision, 'credit_cost' => $cost,
            ]);
            if ($cost > 0) {
                $workspace->credits()->create([
                    'amount' => -$cost, 'event' => 'page_writing', 'description' => mb_substr('AI page writing: '.$locked->name, 0, 250),
                    'idempotency_key' => 'page-generation:'.$generation->id, 'metadata' => ['page_id' => $locked->id, 'product_id' => $locked->product_id],
                ]);
            }

            return [$generation, $document, $targets];
        });
        RateLimiter::hit($limiter, 3600);

        $deadline = app(GenerationDeadline::class);
        $ownsDeadline = $deadline->remaining() === null;
        if ($ownsDeadline) {
            $deadline->start(self::DEADLINE_SECONDS);
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(self::DEADLINE_SECONDS + 40);
        }

        try {
            $page->loadMissing('product.brand');
            $writing = $this->writers->current()->write(ProductBrief::for($page->product), $document, $targets, $instruction);
            if ($kind === 'section' && ! is_array($writing->sections[$sectionId] ?? null)) {
                throw ValidationException::withMessages(['ai' => 'The AI returned nothing for this section. Try again.']);
            }
            $result = $this->builder->edit($page, function (Page $locked) use ($writing, $targets, $kind): array {
                // Written into the page as it is now: only the AI fields of the target sections change.
                $locked->document = PageCopy::merge((array) $locked->document, $writing->sections, $targets, $kind === 'page');
                $locked->document_revision++;
                $locked->save();

                return ['revision' => $locked->document_revision, 'document' => $locked->document];
            });
        } catch (Throwable $exception) {
            $this->fail($generation, $exception);
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            throw ValidationException::withMessages(['ai' => $this->message($exception, $generation)]);
        } finally {
            if ($ownsDeadline) {
                $deadline->clear();
            }
        }

        $model = (string) ($writing->model ?: 'unknown');
        $cost = $this->costs->calculate($model, $writing->inputTokens, $writing->cachedInputTokens, $writing->outputTokens);
        if ($cost === 0.0 && $model !== 'mock' && ($writing->inputTokens + $writing->outputTokens) > 0) {
            // Vertex can report a dated model name; price it as the configured model.
            $cost = $this->costs->calculate((string) config('campaigns.google.model'), $writing->inputTokens, $writing->cachedInputTokens, $writing->outputTokens);
        }
        $generation->update([
            'status' => 'completed', 'result_revision' => $result['revision'], 'model' => mb_substr($model, 0, 80),
            'input_tokens' => $writing->inputTokens, 'output_tokens' => $writing->outputTokens, 'cost_usd' => $cost, 'finished_at' => now(),
        ]);

        return $result + ['generation' => $generation->fresh()];
    }

    /** @return list<string> */
    private function sectionTarget(array $document, string $sectionId): array
    {
        $section = collect($document['sections'] ?? [])->firstWhere('id', $sectionId);
        if (! $section) {
            throw ValidationException::withMessages(['ai' => 'That section is no longer on the page.']);
        }
        if (PageCopy::fields($section['type']) === []) {
            throw ValidationException::withMessages(['ai' => 'The AI does not write this section. It holds what only you can supply, such as reviews, numbers, images or logos.']);
        }

        return [$sectionId];
    }

    /** Runs cut off before they finished (the function hit its time limit) are failed and refunded. */
    private function settleStale(Page $page): void
    {
        $page->generations()->where('status', 'running')->where('created_at', '<', now()->subMinutes(self::STALE_MINUTES))->get()
            ->each(function (PageGeneration $generation): void {
                $generation->update(['status' => 'failed', 'error' => 'Stopped before it finished.', 'finished_at' => now()]);
                $this->refund($generation);
            });
    }

    private function fail(PageGeneration $generation, Throwable $exception): void
    {
        if (! $exception instanceof ValidationException) {
            Log::warning('Page writing failed.', ['page_generation_id' => $generation->id, 'exception' => $exception::class, 'message' => $exception->getMessage()]);
        }
        $generation->update(['status' => 'failed', 'error' => mb_substr($this->message($exception, $generation), 0, 500), 'finished_at' => now()]);
        $this->refund($generation);
    }

    private function refund(PageGeneration $generation): void
    {
        if ($generation->credit_cost < 1) {
            return;
        }
        Workspace::find($generation->workspace_id)?->credits()->firstOrCreate(
            ['idempotency_key' => 'page-generation-refund:'.$generation->id],
            [
                'amount' => $generation->credit_cost, 'event' => 'page_writing_refund', 'description' => 'AI page writing credit returned after a failure',
                'metadata' => ['page_id' => $generation->page_id, 'page_generation_id' => $generation->id],
            ],
        );
    }

    private function message(Throwable $exception, PageGeneration $generation): string
    {
        if ($exception instanceof ValidationException) {
            return (string) collect($exception->errors())->flatten()->first();
        }
        $refund = $generation->credit_cost > 0 ? ' Your credit was returned.' : '';

        return 'The AI could not write this '.($generation->kind === 'page' ? 'page' : 'section').' just now. Nothing on the page changed.'.$refund.' Try again in a minute.';
    }
}
