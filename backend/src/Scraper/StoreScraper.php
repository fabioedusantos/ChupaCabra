<?php
declare(strict_types=1);
namespace ChupaCabra\Scraper;
interface StoreScraper { public function scrape(string $url, string $category, callable $heartbeat): array; }
