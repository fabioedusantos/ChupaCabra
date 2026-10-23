<?php
declare(strict_types=1);
namespace ChupaCabra\Scraper;

use ChupaCabra\ApiException;

final class ScraperFactory
{
    public static function for(string $market, ?string $url = null): StoreScraper
    {
        $hosts=array_map('trim',explode(',',getenv('SCRAPE_HOSTS')?:''));
        $validHosts=match($market) {
            'confianca'=>['www.confianca.com.br'],
            'tauste'=>['tauste.com.br','www.tauste.com.br'],
            default=>throw new ApiException('Raspador não configurado para o mercado '.$market)
        };
        $hostname=$url ? strtolower((string)parse_url($url,PHP_URL_HOST)) : $validHosts[0];
        if(!in_array($hostname,$validHosts,true)) throw new ApiException('Domínio incompatível com o supermercado.');
        if(!in_array($hostname,$hosts,true)) throw new ApiException('Domínio não autorizado para raspagem.');
        $fetcher=new HtmlFetcher($hostname);
        return match($market) {'confianca'=>new ConfiancaScraper($fetcher), 'tauste'=>new TausteScraper($fetcher)};
    }
}
