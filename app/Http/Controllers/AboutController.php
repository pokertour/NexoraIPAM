<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

class AboutController extends Controller
{
    public function __invoke(): View
    {
        $version = config('app.version');
        $repository = config('app.repository');
        $latest = Cache::remember('github-latest-release:'.$repository, now()->addMinutes(15), function () use ($repository): array {
            $request = Http::acceptJson()->connectTimeout(2)->timeout(4);
            if ($token = config('app.release_token')) {
                $request->withToken($token);
            }

            try {
                $response = $request->get('https://api.github.com/repos/'.$repository.'/releases/latest');
            } catch (ConnectionException) {
                return ['status' => 'unavailable'];
            }

            if ($response->notFound()) {
                return ['status' => 'missing'];
            }

            $tag = $response->json('tag_name');
            if (! $response->successful() || ! is_string($tag) || ! preg_match('/\Av[0-9]+\.[0-9]+\.[0-9]+\z/', $tag)) {
                return ['status' => 'unavailable'];
            }

            return ['status' => 'found', 'version' => $tag];
        });

        $published = $latest['version'] ?? null;
        $updateAvailable = $published !== null && preg_match('/\Av?[0-9]+\.[0-9]+\.[0-9]+\z/', $version)
            ? version_compare(ltrim($published, 'v'), ltrim($version, 'v'), '>')
            : null;

        return view('about', [
            'version' => $version,
            'latest' => $latest,
            'updateAvailable' => $updateAvailable,
            'repositoryUrl' => 'https://github.com/'.$repository,
        ]);
    }
}
