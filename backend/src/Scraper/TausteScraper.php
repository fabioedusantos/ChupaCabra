<?php
declare(strict_types=1);
namespace ChupaCabra\Scraper;

use DOMXPath;
use RuntimeException;

/** XPath do Tauste da ImportModel legada, com verificações nulas e limite de páginas. */
final class TausteScraper implements StoreScraper
{
    public function __construct(private HtmlProvider $http) {}

    public function scrape(string $url,string $category,callable $heartbeat): array
    {
        $products=[];$seen=[];
        $max=max(1,min(500,(int)(getenv('SCRAPE_MAX_PAGES')?:200)));
        for($page=1;$page<=$max;$page++) {
            $heartbeat();
            $dom=$this->http->fetch(HtmlFetcher::pageUrl($url,'p',$page));
            $xpath=new DOMXPath($dom);
            $cards=$xpath->query('//li[@class="item product product-item"]/div[@class="product-item-info"]');
            if(!$cards || $cards->length===0) {
                if($page===1)throw new RuntimeException('Tauste: catálogo vazio ou HTML diferente do esperado.');
                return $products;
            }
            $found=0;
            foreach($cards as $card) {
                // Conserva exatamente os seletores de imagem, descrição e preço da implementação antiga.
                $image=$xpath->query('a/span/span/img[@class="product-image-photo"]/@src',$card)?->item(0)?->nodeValue;
                $titles=$xpath->query('div[@class="product details product-item-details"]/strong/a',$card);
                $title=$titles?->item(1)?->textContent ?? $titles?->item(0)?->textContent;
                $priceText=$xpath->query('div[@class="product details product-item-details"]/div/span/span/span',$card)?->item(0)?->textContent;
                if($title===null || $priceText===null)continue;
                $value=(float)str_replace(',','.',str_replace(['.','R$',' '],'',$priceText));
                if(!is_finite($value)||$value<=0)continue;
                $title=mb_substr(trim($title),0,254);
                $key=sha1($title."\n".$category."\n".number_format($value,3,'.',''));
                if(!isset($seen[$key])) { $products[]=['titulo'=>$title,'preco'=>$value,'categoria'=>$category,'imgUrl'=>$image];$seen[$key]=true; }
                $found++;
            }
            if($found===0) throw new RuntimeException('Tauste: estrutura encontrada mas nenhum produto pôde ser extraído.');
            usleep(250000);
        }
        throw new RuntimeException('Tauste: limite de páginas excedido.');
    }
}
