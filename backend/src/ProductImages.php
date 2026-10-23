<?php
declare(strict_types=1);
namespace ChupaCabra;

use PDO;

/**
 * Imagens sob a mesma origem do ChupaCabra: fallback para bloqueio de hotlink.
 * A URL é sempre resolvida no banco, jamais informada pelo cliente HTTP.
 * Apenas domínios oficiais dos supermercados são aceitos (proteção SSRF).
 */
final class ProductImages
{
    private const MAX_BYTES = 3145728; // 3 MiB por imagem
    private const CACHE_TTL = 86400; // um dia; o catálogo é atualizado diariamente

    public function __construct(private PDO $db) {}

    /** @return array{0:string,1:string} [MIME, bytes] */
    public function saved(int $id): array
    {
        $stmt = $this->db->prepare('SELECT titulo,supermercado,imgUrl FROM saved WHERE id=?');
        $stmt->execute([$id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) throw new ApiException('Produto não encontrado.', 404);
        $market = (string)$item['supermercado'];
        // A URL do catálogo recém-sincronizado tem prioridade sobre fotos
        // antigas armazenadas na lista. O campo saved.imgUrl é fallback.
        $url = $this->catalogUrl($market, (string)$item['titulo']);
        if ($url === '') $url = $this->normalize((string)($item['imgUrl'] ?? ''), $market);
        if ($url === '') throw new ApiException('Imagem não disponível.', 404);
        return $this->load($url);
    }

    /** @return array{0:string,1:string} */
    public function catalog(string $market, int $id): array
    {
        $table = $this->table($market);
        $stmt = $this->db->prepare("SELECT imgUrl FROM $table WHERE id=?");
        $stmt->execute([$id]);
        $url = $this->normalize((string)($stmt->fetchColumn() ?: ''), $market);
        if ($url === '') throw new ApiException('Imagem não disponível.', 404);
        return $this->load($url);
    }

    private function catalogUrl(string $market, string $title): string
    {
        $table = $this->table($market);
        $stmt = $this->db->prepare("SELECT imgUrl FROM $table WHERE titulo=? AND imgUrl IS NOT NULL AND imgUrl<>'' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$title]);
        return $this->normalize((string)($stmt->fetchColumn() ?: ''), $market);
    }

    private function table(string $market): string
    {
        if (!in_array($market, ['confianca', 'tauste'], true)) {
            throw new ApiException('Supermercado inválido.', 404);
        }
        return 'data_'.$market;
    }

    private function normalize(string $url, string $market): string
    {
        $url = trim($url);
        if ($url === '') return '';
        if (str_starts_with($url, '//')) $url = 'https:'.$url;
        elseif (str_starts_with($url, 'http://')) $url = 'https://'.substr($url, 7);
        elseif (str_starts_with($url, '/')) $url = 'https://www.'.$market.'.com.br'.$url;
        // Outros endereços relativos não devem virar uma URL arbitrária.
        return $url;
    }

    /** @return array{0:string,1:string} */
    private function load(string $url): array
    {
        $url = $this->checkUrl($url);
        $dir = sys_get_temp_dir().'/chupacabra-product-images';
        $key = hash('sha256', $url);
        $path = $dir.'/'.$key;
        if (is_file($path) && filemtime($path) > time()-self::CACHE_TTL) {
            $cached = file_get_contents($path);
            if (is_string($cached)) {
                $line = strpos($cached, "\n");
                if ($line !== false && $line < 64) {
                    return [substr($cached, 0, $line), substr($cached, $line+1)];
                }
            }
        }
        [$mime, $bytes] = $this->fetch($url);
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        if (is_dir($dir) && is_writable($dir)) {
            $tmp = tempnam($dir, '.image-');
            if ($tmp !== false) {
                file_put_contents($tmp, $mime."\n".$bytes);
                @chmod($tmp, 0600);
                @rename($tmp, $path);
            }
        }
        return [$mime, $bytes];
    }

    private function checkUrl(string $url): string
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $url)) {
            throw new ApiException('Imagem inválida.', 404);
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) || isset($parts['fragment'])) {
            throw new ApiException('Origem da imagem não permitida.', 404);
        }
        $host = strtolower((string)($parts['host'] ?? ''));
        if (!$this->officialHost($host)) throw new ApiException('Origem da imagem não permitida.', 404);
        return $url;
    }

    private function officialHost(string $host): bool
    {
        foreach (['confianca.com.br', 'tauste.com.br'] as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) return true;
        }
        return false;
    }

    /** @return array{0:string,1:string} */
    private function fetch(string $url): array
    {
        // Seguir somente redirecionamentos para outro host oficial validado.
        for ($redirects=0; $redirects<=2; $redirects++) {
            $url = $this->checkUrl($url);
            $host = (string)parse_url($url, PHP_URL_HOST);
            // Fixamos um endereço público verificado para impedir DNS rebinding/SSRF.
            $addresses = gethostbynamel($host) ?: [];
            $public = null;
            foreach ($addresses as $address) {
                if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) {
                    $public = $address;
                    break;
                }
            }
            if (!$public) throw new ApiException('Imagem indisponível no momento.', 404);
            $data = '';
            $ch = curl_init($url);
            if ($ch === false) throw new ApiException('Imagem indisponível.', 404);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 9,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_RESOLVE => ["$host:443:$public"],
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ChupaCabra/2.0; image-viewer)',
                CURLOPT_REFERER => 'https://'.$host.'/',
                CURLOPT_HTTPHEADER => ['Accept: image/avif,image/webp,image/png,image/jpeg,image/gif,image/*;q=0.8'],
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$data): int {
                    if (strlen($data)+strlen($chunk) > self::MAX_BYTES) return 0;
                    $data .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $mime = strtolower(trim(explode(';', (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0]));
            $redirect = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);
            if ($ok === false) throw new ApiException('Imagem indisponível no momento.', 404);
            if ($status >= 300 && $status < 400 && $redirect !== '') {
                if (str_starts_with($redirect, '/')) $redirect = 'https://'.$host.$redirect;
                $url = $redirect;
                continue;
            }
            if ($status !== 200 || !in_array($mime, ['image/jpeg','image/png','image/gif','image/webp','image/avif'], true)
                || $data === '' || @getimagesizefromstring($data) === false) {
                throw new ApiException('Imagem indisponível na origem.', 404);
            }
            return [$mime, $data];
        }
        throw new ApiException('Redirecionamentos excessivos da imagem.', 404);
    }
}
