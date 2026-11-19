<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ReviewQuestions\Pages;

use App\Filament\App\Resources\ReviewQuestions\ReviewQuestionResource;
use App\Filament\CurrentTenant;
use App\Models\ReviewQuestion;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListReviewQuestions extends ListRecords
{
    protected static string $resource = ReviewQuestionResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return 'Asked of the reviewer on a pull request, with @review or under one of its comments, or on a review\'s page.';
    }

    public function getTabs(): array
    {
        $views = [
            'all' => ['All', fn (Builder $query): Builder => $query],
            'pending' => ['Being answered', fn (Builder $query): Builder => $query->whereIn('status', [ReviewQuestion::STATUS_ASKED, ReviewQuestion::STATUS_UNSURE])],
            'answered' => ['Answered', fn (Builder $query): Builder => $query->where('status', ReviewQuestion::STATUS_ANSWERED)],
            'unanswered' => ['No answer', fn (Builder $query): Builder => $query->whereIn('status', [ReviewQuestion::STATUS_FAILED, ReviewQuestion::STATUS_IGNORED])],
        ];

        return array_map(fn (array $view): Tab => Tab::make($view[0])
            ->badge($view[1](CurrentTenant::get()->reviewQuestions()->getQuery())->count())
            ->modifyQueryUsing($view[1]), $views);
    }
}
