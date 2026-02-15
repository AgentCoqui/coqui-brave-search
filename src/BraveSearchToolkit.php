<?php

declare(strict_types=1);

namespace CoquiBrave\BraveSearch;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Brave Search toolkit — provides web and news search via the Brave Search API.
 *
 * Auto-discovered by Coqui's ToolkitDiscovery when installed via Composer.
 * Requires a BRAVE_SEARCH_API_KEY environment variable or .env entry.
 *
 * @see https://api.search.brave.com/app/documentation/web-search/get-started
 */
final class BraveSearchToolkit implements ToolkitInterface
{
    private const string BASE_URL = 'https://api.search.brave.com/res/v1';
    private const int DEFAULT_COUNT = 5;
    private const int MAX_COUNT = 20;
    private const int MAX_SNIPPET_LENGTH = 300;

    private HttpClientInterface $httpClient;

    public function __construct(
        private readonly string $apiKey = '',
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create(['timeout' => 15]);
    }

    /**
     * Factory method for ToolkitDiscovery — reads API key from environment.
     */
    public static function fromEnv(): self
    {
        $apiKey = getenv('BRAVE_SEARCH_API_KEY');

        return new self(apiKey: $apiKey !== false ? $apiKey : '');
    }

    public function tools(): array
    {
        return [
            $this->webSearchTool(),
            $this->newsSearchTool(),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
        <BRAVE-SEARCH-GUIDELINES>
        - Use brave_search for general web searches: current events, technical docs, how-to, factual questions.
        - Use brave_news for recent news and time-sensitive topics.
        - Keep queries concise and specific for better results.
        - Always cite URLs from the results when referencing information.
        - Do not use these tools to search for harmful, illegal, or privacy-violating content.
        </BRAVE-SEARCH-GUIDELINES>
        GUIDELINES;
    }

    private function webSearchTool(): ToolInterface
    {
        return new Tool(
            name: 'brave_search',
            description: 'Search the web using Brave Search. Returns titles, URLs, and descriptions.',
            parameters: [
                new StringParameter('query', 'The search query'),
                new NumberParameter(
                    'count',
                    'Number of results to return (1-20)',
                    required: false,
                    integer: true,
                    minimum: 1,
                    maximum: self::MAX_COUNT,
                ),
                new StringParameter(
                    'country',
                    'Country code for results (e.g. us, gb, de)',
                    required: false,
                ),
                new StringParameter(
                    'search_lang',
                    'Language code for results (e.g. en, fr, es)',
                    required: false,
                ),
            ],
            callback: function (array $input): ToolResult {
                return $this->executeWebSearch($input);
            },
        );
    }

    private function newsSearchTool(): ToolInterface
    {
        return new Tool(
            name: 'brave_news',
            description: 'Search for recent news articles using Brave Search. Returns titles, URLs, descriptions, and publication age.',
            parameters: [
                new StringParameter('query', 'The news search query'),
                new NumberParameter(
                    'count',
                    'Number of results to return (1-20)',
                    required: false,
                    integer: true,
                    minimum: 1,
                    maximum: self::MAX_COUNT,
                ),
            ],
            callback: function (array $input): ToolResult {
                return $this->executeNewsSearch($input);
            },
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function executeWebSearch(array $input): ToolResult
    {
        $query = $input['query'] ?? '';

        if ($query === '') {
            return ToolResult::error('Search query is required.');
        }

        if ($this->resolveApiKey() === '') {
            return ToolResult::error('BRAVE_SEARCH_API_KEY is not configured. Set it in your environment or .env file.');
        }

        $params = [
            'q' => $query,
            'count' => min((int) ($input['count'] ?? self::DEFAULT_COUNT), self::MAX_COUNT),
        ];

        if (isset($input['country']) && $input['country'] !== '') {
            $params['country'] = $input['country'];
        }

        if (isset($input['search_lang']) && $input['search_lang'] !== '') {
            $params['search_lang'] = $input['search_lang'];
        }

        try {
            $response = $this->httpClient->request('GET', self::BASE_URL . '/web/search', [
                'headers' => $this->headers(),
                'query' => $params,
            ]);

            $data = $response->toArray();

            return ToolResult::success($this->formatWebResults($data));
        } catch (HttpExceptionInterface $e) {
            $body = $this->extractErrorBody($e);

            return ToolResult::error("Brave web search failed (HTTP {$e->getResponse()->getStatusCode()}): {$body}");
        } catch (\Throwable $e) {
            return ToolResult::error("Brave web search failed: {$e->getMessage()}");
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function executeNewsSearch(array $input): ToolResult
    {
        $query = $input['query'] ?? '';

        if ($query === '') {
            return ToolResult::error('Search query is required.');
        }

        if ($this->resolveApiKey() === '') {
            return ToolResult::error('BRAVE_SEARCH_API_KEY is not configured. Set it in your environment or .env file.');
        }

        $params = [
            'q' => $query,
            'count' => min((int) ($input['count'] ?? self::DEFAULT_COUNT), self::MAX_COUNT),
        ];

        try {
            $response = $this->httpClient->request('GET', self::BASE_URL . '/news/search', [
                'headers' => $this->headers(),
                'query' => $params,
            ]);

            $data = $response->toArray();

            return ToolResult::success($this->formatNewsResults($data));
        } catch (HttpExceptionInterface $e) {
            $body = $this->extractErrorBody($e);

            return ToolResult::error("Brave news search failed (HTTP {$e->getResponse()->getStatusCode()}): {$body}");
        } catch (\Throwable $e) {
            return ToolResult::error("Brave news search failed: {$e->getMessage()}");
        }
    }

    /**
     * Resolve the API key lazily — checks constructor value, then process environment.
     *
     * This enables hot-reload: after CredentialTool saves a key via putenv(),
     * the next tool call picks it up without restarting.
     */
    private function resolveApiKey(): string
    {
        if ($this->apiKey !== '') {
            return $this->apiKey;
        }

        $envKey = getenv('BRAVE_SEARCH_API_KEY');

        return $envKey !== false ? $envKey : '';
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Accept' => 'application/json',
            'X-Subscription-Token' => $this->resolveApiKey(),
        ];
    }

    /**
     * Format web search API response into a compact result string.
     *
     * @param array<string, mixed> $data
     */
    private function formatWebResults(array $data): string
    {
        $results = $data['web']['results'] ?? [];

        if (empty($results)) {
            return json_encode(['results' => [], 'message' => 'No results found.'], JSON_THROW_ON_ERROR);
        }

        $formatted = [];

        foreach ($results as $result) {
            $formatted[] = [
                'title' => $result['title'] ?? '',
                'url' => $result['url'] ?? '',
                'description' => $this->cleanSnippet($result['description'] ?? ''),
            ];
        }

        return json_encode(['results' => $formatted], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    /**
     * Format news search API response into a compact result string.
     *
     * @param array<string, mixed> $data
     */
    private function formatNewsResults(array $data): string
    {
        $results = $data['results'] ?? [];

        if (empty($results)) {
            return json_encode(['results' => [], 'message' => 'No news results found.'], JSON_THROW_ON_ERROR);
        }

        $formatted = [];

        foreach ($results as $result) {
            $formatted[] = [
                'title' => $result['title'] ?? '',
                'url' => $result['url'] ?? '',
                'description' => $this->cleanSnippet($result['description'] ?? ''),
                'age' => $result['age'] ?? '',
            ];
        }

        return json_encode(['results' => $formatted], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    /**
     * Extract a readable error body from an HTTP exception response.
     */
    private function extractErrorBody(HttpExceptionInterface $e): string
    {
        try {
            $body = $e->getResponse()->getContent(false);
            $decoded = json_decode($body, true);

            if (is_array($decoded)) {
                // Brave API errors typically have a 'detail' or 'message' field
                return $decoded['detail'] ?? $decoded['message'] ?? $decoded['error'] ?? $body;
            }

            return mb_substr($body, 0, 500);
        } catch (\Throwable) {
            return $e->getMessage();
        }
    }

    /**
     * Strip HTML tags and truncate a snippet to a reasonable length.
     */
    private function cleanSnippet(string $text): string
    {
        $clean = strip_tags($text);
        $clean = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? $clean);

        if (mb_strlen($clean) > self::MAX_SNIPPET_LENGTH) {
            return mb_substr($clean, 0, self::MAX_SNIPPET_LENGTH) . '…';
        }

        return $clean;
    }
}
