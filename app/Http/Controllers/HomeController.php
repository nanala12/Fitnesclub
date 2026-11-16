<?php

namespace App\Http\Controllers;

use App\Actions\PublicSite\PublicSiteContent;
use App\Models\NewsPost;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class HomeController extends Controller
{
    public function __invoke(PublicSiteContent $content): Response
    {
        return Inertia::render('Welcome', [
            'canRegister' => Features::enabled(Features::registration()),
            ...$content->shared(),
            'tiles' => $content->offers(), 'slides' => $content->slides(), 'featuredPlans' => [],
            'news' => $content->publishedNews()->with('media')->orderByDesc('published_at')->orderByDesc('id')->limit(3)->get()->map(fn (NewsPost $post): array => $content->newsCard($post)),
        ]);
    }
}
