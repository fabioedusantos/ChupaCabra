<?php
declare(strict_types=1);
namespace ChupaCabra\Scraper;

use DOMDocument;
use RuntimeException;

final class HtmlFetcher implements HtmlProvider
{
    public function __construct(private string $allowedHost) {}

    public function fetch(string $url): DOMDocument
    {
        for ($redirect=0;$redirect<4;$redirect++) {
            $parts=parse_url($url);
            if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || strtolower((string)($parts['host'] ?? '')) !== $this->allowedHost || isset($parts['user']) || isset($parts['port'])) {
                throw new RuntimeException('URL não autorizada para este supermercado.');
            }
            $error = '';
            $code = 0;
            $handle=curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36',
                CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>60,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8','Accept-Language: pt-BR,pt;q=0.9,en;q=0.8'],
                CURLOPT_ENCODING=>'',
            ]);
            $html=curl_exec($handle);
            $code=(int)curl_getinfo($handle,CURLINFO_HTTP_CODE);
            $error=curl_error($handle);
            $location='';
            if ($code>=300 && $code<400) {
                // Headers armazenados na segunda solicitação, sem seguir redirecionamentos cegamente.
                curl_setopt($handle,CURLOPT_NOBODY,true);
                curl_setopt($handle,CURLOPT_HEADERFUNCTION, static function($h,$line) use (&$location) { if (stripos($line,'Location:')===0) $location=trim(substr($line,9)); return strlen($line); });
                curl_exec($handle);
            }
            curl_close($handle);
            if ($code>=300 && $code<400 && $location!=='') {
                if (str_starts_with($location,'/')) $url='https://'.$this->allowedHost.$location;
                else $url=$location;
                continue;
            }
            if ($html===false || $code<200 || $code>=300) throw new RuntimeException('Falha na página: HTTP '.$code.' '.$error);
            if (strlen($html)>16*1024*1024) throw new RuntimeException('Página excedeu o limite de 16 MB.');
            $dom=new DOMDocument();
            $previous=libxml_use_internal_errors(true);
            try { if (!$dom->loadHTML($html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING)) throw new RuntimeException('HTML inválido.'); }
            finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
            return $dom;
        }
        throw new RuntimeException('Redirecionamentos excessivos.');
    }

    public static function pageUrl(string $url,string $key,int $page): string
    {
        $parts=parse_url($url);
        parse_str((string)($parts['query']??''),$query);
        $query[$key]=$page;
        return ($parts['scheme']??'https').'://'.($parts['host']??'').($parts['path']??'/').'?'.http_build_query($query);
    }
}
