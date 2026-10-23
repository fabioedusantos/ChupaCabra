<?php
declare(strict_types=1);
namespace ChupaCabra\Scraper;

interface HtmlProvider
{
    public function fetch(string $url): \DOMDocument;
}
