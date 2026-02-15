<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBrave\BraveSearch\BraveSearchToolkit;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// -- Factory & Configuration --

test('fromEnv reads BRAVE_SEARCH_API_KEY from environment', function () {
    $original = getenv('BRAVE_SEARCH_API_KEY');

    putenv('BRAVE_SEARCH_API_KEY=test-brave-key-123');
    $toolkit = BraveSearchToolkit::fromEnv();

    expect($toolkit)->toBeInstanceOf(BraveSearchToolkit::class)
        ->and($toolkit->tools())->toHaveCount(2);

    // Restore
    if ($original !== false) {
        putenv("BRAVE_SEARCH_API_KEY={$original}");
    } else {
        putenv('BRAVE_SEARCH_API_KEY');
    }
});

test('fromEnv handles missing environment variable', function () {
    $original = getenv('BRAVE_SEARCH_API_KEY');

    putenv('BRAVE_SEARCH_API_KEY');
    $toolkit = BraveSearchToolkit::fromEnv();

    // Should create toolkit but tools will return error when called
    expect($toolkit)->toBeInstanceOf(BraveSearchToolkit::class);

    // Restore
    if ($original !== false) {
        putenv("BRAVE_SEARCH_API_KEY={$original}");
    }
});

// -- Tool Registration --

test('tools returns brave_search and brave_news', function () {
    $toolkit = new BraveSearchToolkit(apiKey: 'test-key');
    $tools = $toolkit->tools();

    expect($tools)->toHaveCount(2)
        ->and($tools[0]->name())->toBe('brave_search')
        ->and($tools[1]->name())->toBe('brave_news');
});

test('guidelines returns non-empty string with brave search guidance', function () {
    $toolkit = new BraveSearchToolkit(apiKey: 'test-key');
    $guidelines = $toolkit->guidelines();

    expect($guidelines)->toBeString()
        ->and($guidelines)->toContain('brave_search')
        ->and($guidelines)->toContain('brave_news');
});

test('brave_search tool generates valid function schema', function () {
    $toolkit = new BraveSearchToolkit(apiKey: 'test-key');
    $tool = $toolkit->tools()[0];
    $schema = $tool->toFunctionSchema();

    expect($schema['type'])->toBe('function')
        ->and($schema['function']['name'])->toBe('brave_search')
        ->and($schema['function']['parameters']['properties'])->toHaveKey('query')
        ->and($schema['function']['parameters']['properties'])->toHaveKey('count')
        ->and($schema['function']['parameters']['properties'])->toHaveKey('country')
        ->and($schema['function']['parameters']['properties'])->toHaveKey('search_lang')
        ->and($schema['function']['parameters']['required'])->toBe(['query']);
});

test('brave_news tool generates valid function schema', function () {
    $toolkit = new BraveSearchToolkit(apiKey: 'test-key');
    $tool = $toolkit->tools()[1];
    $schema = $tool->toFunctionSchema();

    expect($schema['type'])->toBe('function')
        ->and($schema['function']['name'])->toBe('brave_news')
        ->and($schema['function']['parameters']['properties'])->toHaveKey('query')
        ->and($schema['function']['parameters']['properties'])->toHaveKey('count')
        ->and($schema['function']['parameters']['required'])->toBe(['query']);
});

// -- Error Handling --

test('brave_search returns error when query is empty', function () {
    $toolkit = new BraveSearchToolkit(apiKey: 'test-key');
    $tool = $toolkit->tools()[0];

    $result = $tool->execute(['query' => '']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Search query is required');
});

test('brave_search returns error when API key is missing', function () {
    $toolkit = new BraveSearchToolkit(apiKey: '');
    $tool = $toolkit->tools()[0];

    $result = $tool->execute(['query' => 'test']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('BRAVE_SEARCH_API_KEY');
});

test('brave_news returns error when query is empty', function () {
    $toolkit = new BraveSearchToolkit(apiKey: 'test-key');
    $tool = $toolkit->tools()[1];

    $result = $tool->execute(['query' => '']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Search query is required');
});

test('brave_news returns error when API key is missing', function () {
    $toolkit = new BraveSearchToolkit(apiKey: '');
    $tool = $toolkit->tools()[1];

    $result = $tool->execute(['query' => 'test']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('BRAVE_SEARCH_API_KEY');
});

// -- Web Search Execution --

test('brave_search formats web results correctly', function () {
    $mockResponse = new MockResponse(json_encode([
        'web' => [
            'results' => [
                [
                    'title' => 'PHP 8.4 Release',
                    'url' => 'https://www.php.net/releases/8.4/',
                    'description' => 'PHP 8.4 introduces <strong>property hooks</strong> and asymmetric visibility.',
                ],
                [
                    'title' => 'What\u0027s New in PHP 8.4',
                    'url' => 'https://stitcher.io/blog/new-in-php-84',
                    'description' => 'A comprehensive overview of <em>all</em> new features in PHP 8.4.',
                ],
            ],
        ],
    ]));

    $mockClient = new MockHttpClient([$mockResponse]);
    $toolkit = new BraveSearchToolkit(apiKey: 'test-key', httpClient: $mockClient);
    $tool = $toolkit->tools()[0];

    $result = $tool->execute(['query' => 'PHP 8.4']);

    expect($result->status)->toBe(ToolResultStatus::Success);

    $parsed = json_decode($result->content, true);

    expect($parsed['results'])->toHaveCount(2)
        ->and($parsed['results'][0]['title'])->toBe('PHP 8.4 Release')
        ->and($parsed['results'][0]['url'])->toBe('https://www.php.net/releases/8.4/')
        // HTML tags should be stripped
        ->and($parsed['results'][0]['description'])->not->toContain('<strong>')
        ->and($parsed['results'][0]['description'])->toContain('property hooks');
});

test('brave_search handles empty results', function () {
    $mockResponse = new MockResponse(json_encode([
        'web' => ['results' => []],
    ]));

    $mockClient = new MockHttpClient([$mockResponse]);
    $toolkit = new BraveSearchToolkit(apiKey: 'test-key', httpClient: $mockClient);
    $tool = $toolkit->tools()[0];

    $result = $tool->execute(['query' => 'xyznonexistentquery123']);

    expect($result->status)->toBe(ToolResultStatus::Success);

    $parsed = json_decode($result->content, true);

    expect($parsed['results'])->toBeEmpty()
        ->and($parsed['message'])->toBe('No results found.');
});

test('brave_search sends correct headers and query parameters', function () {
    $capturedUrl = '';
    $capturedOptions = [];

    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedUrl, &$capturedOptions): MockResponse {
        $capturedUrl = $url;
        $capturedOptions = $options;
        return new MockResponse(json_encode(['web' => ['results' => []]]));
    });

    $toolkit = new BraveSearchToolkit(apiKey: 'my-secret-key', httpClient: $mockClient);
    $tool = $toolkit->tools()[0];

    $tool->execute([
        'query' => 'test query',
        'count' => 10,
        'country' => 'us',
        'search_lang' => 'en',
    ]);

    expect($capturedUrl)->toContain('/web/search')
        ->and($capturedOptions['query']['q'])->toBe('test query')
        ->and($capturedOptions['query']['count'])->toBe(10)
        ->and($capturedOptions['query']['country'])->toBe('us')
        ->and($capturedOptions['query']['search_lang'])->toBe('en');

    // Headers are normalized by Symfony: lowercase keys, values are arrays of "Header: value" strings
    $tokenHeader = $capturedOptions['normalized_headers']['x-subscription-token'][0] ?? '';
    expect($tokenHeader)->toContain('my-secret-key');

    // Must NOT set Accept-Encoding — Symfony HttpClient handles gzip automatically.
    // Manually setting it disables auto-decompression, causing json_decode to fail on raw gzip bytes.
    expect($capturedOptions['normalized_headers'])->not->toHaveKey('accept-encoding');
});

test('brave_search caps count at 20', function () {
    $capturedOptions = [];

    $mockClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions): MockResponse {
        $capturedOptions = $options;
        return new MockResponse(json_encode(['web' => ['results' => []]]));
    });

    $toolkit = new BraveSearchToolkit(apiKey: 'test-key', httpClient: $mockClient);
    $tool = $toolkit->tools()[0];

    $tool->execute(['query' => 'test', 'count' => 50]);

    expect($capturedOptions['query']['count'])->toBe(20);
});

test('brave_search handles HTTP errors gracefully', function () {
    $mockClient = new MockHttpClient([
        new MockResponse('{"detail": "Too many requests"}', ['http_code' => 429]),
    ]);

    $toolkit = new BraveSearchToolkit(apiKey: 'test-key', httpClient: $mockClient);
    $tool = $toolkit->tools()[0];

    $result = $tool->execute(['query' => 'test']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Brave web search failed')
        ->and($result->content)->toContain('429')
        ->and($result->content)->toContain('Too many requests');
});

// -- News Search Execution --

test('brave_news formats news results correctly', function () {
    $mockResponse = new MockResponse(json_encode([
        'results' => [
            [
                'title' => 'PHP 8.4 Released Today',
                'url' => 'https://example.com/php-84-released',
                'description' => 'The <strong>PHP</strong> team announced version 8.4 today.',
                'age' => '2 hours ago',
            ],
            [
                'title' => 'New Features in PHP 8.4',
                'url' => 'https://example.com/php-84-features',
                'description' => 'An overview of the <em>major</em> new features.',
                'age' => '5 hours ago',
            ],
        ],
    ]));

    $mockClient = new MockHttpClient([$mockResponse]);
    $toolkit = new BraveSearchToolkit(apiKey: 'test-key', httpClient: $mockClient);
    $tool = $toolkit->tools()[1];

    $result = $tool->execute(['query' => 'PHP 8.4 release']);

    expect($result->status)->toBe(ToolResultStatus::Success);

    $parsed = json_decode($result->content, true);

    expect($parsed['results'])->toHaveCount(2)
        ->and($parsed['results'][0]['title'])->toBe('PHP 8.4 Released Today')
        ->and($parsed['results'][0]['url'])->toBe('https://example.com/php-84-released')
        ->and($parsed['results'][0]['description'])->not->toContain('<strong>')
        ->and($parsed['results'][0]['age'])->toBe('2 hours ago');
});

test('brave_news handles empty results', function () {
    $mockResponse = new MockResponse(json_encode([
        'results' => [],
    ]));

    $mockClient = new MockHttpClient([$mockResponse]);
    $toolkit = new BraveSearchToolkit(apiKey: 'test-key', httpClient: $mockClient);
    $tool = $toolkit->tools()[1];

    $result = $tool->execute(['query' => 'xyznonexistent']);

    expect($result->status)->toBe(ToolResultStatus::Success);

    $parsed = json_decode($result->content, true);

    expect($parsed['results'])->toBeEmpty()
        ->and($parsed['message'])->toBe('No news results found.');
});

test('brave_news handles HTTP errors gracefully', function () {
    $mockClient = new MockHttpClient([
        new MockResponse('{"detail": "Unauthorized"}', ['http_code' => 401]),
    ]);

    $toolkit = new BraveSearchToolkit(apiKey: 'test-key', httpClient: $mockClient);
    $tool = $toolkit->tools()[1];

    $result = $tool->execute(['query' => 'test']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Brave news search failed')
        ->and($result->content)->toContain('401')
        ->and($result->content)->toContain('Unauthorized');
});

// -- Snippet Sanitization --

test('descriptions have HTML stripped and are truncated', function () {
    $longDescription = str_repeat('This is a <b>test</b> description. ', 30);

    $mockResponse = new MockResponse(json_encode([
        'web' => [
            'results' => [
                [
                    'title' => 'Test',
                    'url' => 'https://example.com',
                    'description' => $longDescription,
                ],
            ],
        ],
    ]));

    $mockClient = new MockHttpClient([$mockResponse]);
    $toolkit = new BraveSearchToolkit(apiKey: 'test-key', httpClient: $mockClient);
    $tool = $toolkit->tools()[0];

    $result = $tool->execute(['query' => 'test']);
    $parsed = json_decode($result->content, true);

    $desc = $parsed['results'][0]['description'];

    expect($desc)->not->toContain('<b>')
        ->and($desc)->not->toContain('</b>')
        ->and(mb_strlen($desc))->toBeLessThanOrEqual(301); // 300 + ellipsis char
});
