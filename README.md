# Coqui Brave Search

A [Brave Search API](https://brave.com/search/api/) toolkit for [Coqui](https://github.com/coquibot/coqui). Provides web search and news search tools that agents can use to find up-to-date information.

## Requirements

- PHP 8.4+
- A [Brave Search API key](https://brave.com/search/api/) (free tier: 2,000 queries/month)

## Installation

```bash
composer require coquibot/coqui-brave-search
```

When installed alongside Coqui, the toolkit is **auto-discovered** via Composer's `extra.php-agents.toolkits` — no manual registration needed.

## Configuration

Set your API key as an environment variable:

```bash
export BRAVE_SEARCH_API_KEY=your-api-key-here
```

Or add it to your `.env` file:

```
BRAVE_SEARCH_API_KEY=your-api-key-here
```

## Tools Provided

### `brave_search`

Search the web using Brave Search. Returns titles, URLs, and descriptions.

| Parameter     | Type    | Required | Description                            |
|---------------|---------|----------|----------------------------------------|
| `query`       | string  | Yes      | The search query                       |
| `count`       | integer | No       | Number of results (1–20, default 5)    |
| `country`     | string  | No       | Country code (e.g. `us`, `gb`, `de`)   |
| `search_lang` | string  | No       | Language code (e.g. `en`, `fr`, `es`)  |

### `brave_news`

Search for recent news articles. Returns titles, URLs, descriptions, and publication age.

| Parameter | Type    | Required | Description                         |
|-----------|---------|----------|-------------------------------------|
| `query`   | string  | Yes      | The news search query               |
| `count`   | integer | No       | Number of results (1–20, default 5) |

## Standalone Usage

You can use the toolkit outside of Coqui:

```php
<?php

declare(strict_types=1);

use CoquiBrave\BraveSearch\BraveSearchToolkit;

require __DIR__ . '/vendor/autoload.php';

$toolkit = BraveSearchToolkit::fromEnv();

// Or pass the API key directly
// $toolkit = new BraveSearchToolkit(apiKey: 'your-api-key');

foreach ($toolkit->tools() as $tool) {
    echo $tool->name() . ': ' . $tool->description() . PHP_EOL;
}

// Execute a web search
$result = $toolkit->tools()[0]->execute(['query' => 'PHP 8.4 new features']);
echo $result->content;
```

## Development

```bash
git clone https://github.com/coquibot/coqui-brave-search.git
cd coqui-brave-search
composer install
```

### Run tests

```bash
./vendor/bin/pest
```

### Static analysis

```bash
./vendor/bin/phpstan analyse
```

## License

MIT
