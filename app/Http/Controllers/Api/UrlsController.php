<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Url;
use App\Services\SitemapGeneratorService;
use App\Services\UrlClassifierService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UrlsController extends Controller
{
    public function __construct(
        private readonly SitemapGeneratorService $generator,
        private readonly UrlClassifierService $classifier,
    ) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'patternType' => ['sometimes', Rule::in(Url::PATTERN_TYPES)],
            'lang' => ['sometimes', 'string'],
            'hidden' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'pageSize' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $page = $data['page'] ?? 1;
        $pageSize = $data['pageSize'] ?? 50;

        $query = Url::query();
        if (isset($data['patternType'])) $query->where('pattern_type', $data['patternType']);
        if (isset($data['lang'])) $query->where('lang', $data['lang']);
        if (isset($data['hidden'])) $query->where('is_hidden', $data['hidden']);
        if (isset($data['active'])) $query->where('is_active', $data['active']);
        if (isset($data['search'])) $query->where('source_url', 'ilike', '%' . $data['search'] . '%');

        $total = (clone $query)->count();
        $items = $query->orderBy('source_url')
            ->skip(($page - 1) * $pageSize)
            ->take($pageSize)
            ->get();

        return response()->json(['items' => $items, 'total' => $total, 'page' => $page, 'pageSize' => $pageSize]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'sourceUrl' => ['required', 'url'],
            'patternType' => ['required', Rule::in(Url::PATTERN_TYPES)],
            'lang' => ['sometimes', 'string'],
        ]);

        $classified = $this->classifier->classify($data['sourceUrl']);

        $url = Url::query()->create([
            'source_url' => $data['sourceUrl'],
            'path' => $classified['path'],
            'lang' => $data['lang'] ?? $classified['lang'],
            'pattern_type' => $data['patternType'],
            'slug' => $classified['slug'],
            'group_key' => $classified['group_key'],
            'is_manual' => true,
            'is_active' => true,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->generator->generate();

        return response()->json($url, 201);
    }

    public function bulkVisibility(Request $request)
    {
        $data = $request->validate([
            'hidden' => ['required', 'boolean'],
            'ids' => ['sometimes', 'array'],
            'ids.*' => ['integer'],
            'patternType' => ['sometimes', Rule::in(Url::PATTERN_TYPES)],
            'lang' => ['sometimes', 'string'],
        ]);

        if (empty($data['ids']) && ! isset($data['patternType']) && ! isset($data['lang'])) {
            throw ValidationException::withMessages(['ids' => ['Provide ids, patternType, or lang to select which URLs to update']]);
        }

        $query = Url::query();
        if (! empty($data['ids'])) $query->whereIn('id', $data['ids']);
        if (isset($data['patternType'])) $query->where('pattern_type', $data['patternType']);
        if (isset($data['lang'])) $query->where('lang', $data['lang']);

        $updated = $query->update(['is_hidden' => $data['hidden']]);
        $this->generator->generate();

        return response()->json(['updated' => $updated]);
    }

    public function update(Request $request, Url $url)
    {
        $data = $request->validate([
            'isHidden' => ['sometimes', 'boolean'],
            'patternType' => ['sometimes', Rule::in(Url::PATTERN_TYPES)],
            'priority' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'changefreq' => ['sometimes', 'string'],
        ]);

        $update = [];
        if (array_key_exists('isHidden', $data)) $update['is_hidden'] = $data['isHidden'];
        if (array_key_exists('patternType', $data)) {
            $update['pattern_type'] = $data['patternType'];
            $update['is_manual'] = true;
        }
        if (array_key_exists('priority', $data)) $update['priority'] = $data['priority'];
        if (array_key_exists('changefreq', $data)) $update['changefreq'] = $data['changefreq'];

        $url->update($update);
        $this->generator->generate();

        return response()->json($url->fresh());
    }

    public function destroy(Url $url)
    {
        if (! $url->is_manual && $url->is_active) {
            throw ValidationException::withMessages(['id' => ['Only manually-added or inactive URLs can be deleted; hide it instead']]);
        }

        $url->delete();
        $this->generator->generate();

        return response()->json(['deleted' => true]);
    }
}
