<?php
declare(strict_types=1);
namespace ChupaCabra\Scraper;

use DOMDocument;
use DOMXPath;
use RuntimeException;

/** Portado da ImportModel legada: mesmo window.state, searchRepository,
 *  hierarquia de preço SKU, paginação por first/lastRecNum e assinatura por página. */
final class ConfiancaScraper implements StoreScraper
{
    public function __construct(private HtmlProvider $http) {}
    public function scrape(string $url,string $category,callable $heartbeat): array
    {
        $seenProducts=[];$products=[];$seenPages=[];
        $max=max(1,min(500,(int)(getenv('SCRAPE_MAX_PAGES')?:200)));
        for($page=1;$page<=$max;$page++) {
            $heartbeat();
            $dom=$this->http->fetch(HtmlFetcher::pageUrl($url,'page',$page));
            $state=$this->extractState($dom);
            $pages=$state['searchRepository']['pages']??null;
            if(!is_array($pages)) throw new RuntimeException('Confiança: searchRepository não encontrado.');
            $result=null;
            foreach($pages as $p) if(is_array($p) && isset($p['results'])) { $result=$p['results'];break; }
            if(!is_array($result) || !isset($result['records']) || !is_array($result['records'])) throw new RuntimeException('Confiança: registros ausentes.');
            $current=[];
            foreach($result['records'] as $record) {
                $attr=$record['attributes']??[];
                $title=$attr['product.displayName'][0]??null;
                $price=$this->price($record);
                if($title===null||$price===null||!is_numeric($price)||((float)$price)<=0) continue;
                $img=$attr['product.primaryFullImageURL'][0]??null;
                if($img && !preg_match('~^https?://~i',$img)) $img='https://www.confianca.com.br/'.ltrim($img,'/');
                $product=['titulo'=>mb_substr((string)$title,0,254),'preco'=>(float)$price,'categoria'=>$category,'imgUrl'=>$img];
                $key=sha1($product['titulo']."\n".$category."\n".number_format((float)$price,3,'.',''));
                $current[$key]=$product;
            }
            if (!$current && (int)($result['totalNumRecs']??0)>0) throw new RuntimeException('Confiança: a página informou produtos, mas nenhum produto válido foi extraído.');
            $keys=array_keys($current);sort($keys,SORT_STRING);$signature=sha1(implode('|',$keys));
            if(isset($seenPages[$signature])) throw new RuntimeException('Confiança: página repetida; raspagem interrompida para prevenir duplicações.');
            $seenPages[$signature]=true;
            foreach($current as $key=>$p) if(!isset($seenProducts[$key])) { $products[]=$p;$seenProducts[$key]=true; }
            $last=(int)($result['lastRecNum']??0);
            $total=(int)($result['totalNumRecs']??0);
            if(!($total>0 && $last>0 && $last<$total)) return $products;
            usleep(250000);
        }
        throw new RuntimeException('Confiança: limite de páginas excedido.');
    }

    private function extractState(DOMDocument $dom): array
    {
        $scripts=(new DOMXPath($dom))->query('//script');
        $prefix='window.state = JSON.parse(decodeURI(';
        foreach($scripts as $script) {
            $content=trim($script->textContent);$pos=strpos($content,$prefix);
            if($pos===false)continue;
            $state=substr($content,$pos+strlen($prefix));
            $state=trim((string)preg_replace('/\)\)\s*;?\s*$/','',$state));
            if(strlen($state)>=2 && (($state[0]==='"' && substr($state,-1)==='"') || ($state[0]==="'" && substr($state,-1)==="'"))) $state=substr($state,1,-1);
            $decoded=json_decode(rawurldecode($state),true);
            if(is_array($decoded))return $decoded;
        }
        throw new RuntimeException('Confiança: window.state não foi encontrado.');
    }

    private function price(array $record): mixed
    {
        if(!empty($record['records']) && is_array($record['records'])) foreach($record['records'] as $sku) {
            $price=$sku['attributes']['sku.activePrice'][0]??null;
            if($price!==null && $price!=='') return $price;
        }
        foreach(['sku.minActivePrice','sku.activePrice'] as $attribute) {
            $price=$record['attributes'][$attribute][0]??null;
            if($price!==null && $price!=='')return $price;
        }
        return null;
    }
}
