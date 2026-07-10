<?php

namespace App\Http\Controllers;

use App\Services\SitemapGeneratorService;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class SitemapController extends Controller
{
    public function __construct(private readonly SitemapGeneratorService $generator) {}

    public function index(): Response
    {
        return $this->xml($this->generator->getIndexXml());
    }

    public function category(string $category): Response
    {
        if (! in_array($category, SitemapGeneratorService::CATEGORIES, true)) {
            abort(404, "Unknown sitemap category \"{$category}\"");
        }

        $xml = $this->generator->getCategoryXml($category);
        if ($xml === null) {
            abort(404);
        }

        return $this->xml($xml);
    }

    private function xml(string $content): Response
    {
        return response($content, SymfonyResponse::HTTP_OK)->header('Content-Type', 'application/xml');
    }
}
