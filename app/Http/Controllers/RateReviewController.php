<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ReviewRun;
use App\Support\SecretFilter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The page behind "Rate this review" in a pull request: what someone thought of
 * one run, from 1 (useless) to 5 (spot on), and which findings were fair.
 *
 * Reached by the run's own token, so whoever can read the pull request can rate
 * its review without an account here.
 */
class RateReviewController extends Controller
{
    public function show(string $token): View
    {
        $run = $this->run($token);

        // The page is anyone's who has the link, which the pull request shows: what
        // the review wrote goes through the tenant's secret filter, as it does on
        // its way to the host. Only shown, never saved.
        $filter = SecretFilter::forTenant($run->tenant_id);
        $run->summary = $run->summary === null ? null : $filter->filter($run->summary);
        $run->findings = $run->findings === null ? null : $filter->filterValue($run->findings);

        return view('reviews.rate', ['run' => $run, 'saved' => session('saved', false)]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $run = $this->run($token);

        $data = $request->validate([
            'score' => ['required', 'integer', 'between:1,5'],
            'name' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:5000'],
            'findings' => ['nullable', 'array'],
            'findings.*' => ['in:fair,unfair'],
        ]);

        $known = array_map('strval', array_column($run->findings ?? [], 'id'));

        $run->ratings()->create([
            'user_id' => $request->user()?->getKey(),
            'name' => $data['name'] ?? null,
            'score' => (int) $data['score'],
            'comment' => $data['comment'] ?? null,
            'findings' => array_map(
                fn (string $verdict): bool => $verdict === 'fair',
                array_intersect_key($data['findings'] ?? [], array_flip($known)),
            ) ?: null,
        ]);

        return redirect()->route('reviews.rate', ['token' => $token])->with('saved', true);
    }

    protected function run(string $token): ReviewRun
    {
        return ReviewRun::query()->where('rating_token', $token)->firstOrFail();
    }
}
