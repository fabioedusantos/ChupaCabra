<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
\ChupaCabra\Bootstrap::init();

use ChupaCabra\ApiException;
use ChupaCabra\Auth;
use ChupaCabra\Bootstrap;
use ChupaCabra\Shopping;
use ChupaCabra\PurchaseHistory;
use ChupaCabra\ProductImages;
use ChupaCabra\Jobs;
use ChupaCabra\Sources;
use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$app=AppFactory::create();
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$auth=new Auth(Bootstrap::redis());
$db=Bootstrap::db();
$shopping=new Shopping($db);
$purchases=new PurchaseHistory($db);
$productImages=new ProductImages($db);
$jobs=new Jobs($db,Bootstrap::redis());
$sourcesAdmin=new Sources($db);

function jsonResponse(Response $response, mixed $data, int $status=200): Response {
    $response->getBody()->write(json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE));
    return $response->withStatus($status)->withHeader('Content-Type','application/json; charset=utf-8');
}
function bodyData(Request $request): array {
    $body=$request->getParsedBody();
    if(!is_array($body))throw new ApiException('JSON inválido.');
    return $body;
}
function idParam(string $id): int { if(!ctype_digit($id) || (int)$id<1)throw new ApiException('Identificador inválido.'); return (int)$id; }

$app->post('/api/auth/login',function(Request $rq, Response $rs) use($auth) {
    $data=bodyData($rq);$name=(string)($data['username']??'');$password=(string)($data['password']??'');
    if(strlen($name)>80 || strlen($password)>1024) throw new ApiException('Credenciais inválidas.');
    return jsonResponse($rs,$auth->login($name,$password,(string)($_SERVER['REMOTE_ADDR']??'unknown')));
});
$app->get('/api/auth/session',function(Request $rq, Response $rs) use($auth) {
    $session=$auth->session();
    return jsonResponse($rs,['authenticated'=>(bool)$session, 'username'=>$session['username']??null,'csrf'=>$session['csrf']??null]);
});
$app->post('/api/auth/logout',function(Request $rq, Response $rs) use($auth) {$auth->logout();return jsonResponse($rs,['ok'=>true]);});

// As imagens são consultadas pelo ID, não por uma URL arbitrária enviada pelo
// navegador; isso evita que o proxy se transforme em um endpoint SSRF.
$app->get('/api/images/saved/{id}',function(Request $rq,Response $rs,array $args) use($auth,$productImages) {
    $auth->requireSession();
    [$mime,$bytes]=$productImages->saved(idParam($args['id']));
    $rs->getBody()->write($bytes);
    return $rs->withHeader('Content-Type',$mime)
              ->withHeader('Cache-Control','private, max-age=3600')
              ->withHeader('X-Content-Type-Options','nosniff');
});
$app->get('/api/images/catalog/{market}/{id}',function(Request $rq,Response $rs,array $args) use($auth,$productImages) {
    $auth->requireSession();
    [$mime,$bytes]=$productImages->catalog($args['market'],idParam($args['id']));
    $rs->getBody()->write($bytes);
    return $rs->withHeader('Content-Type',$mime)
              ->withHeader('Cache-Control','private, max-age=3600')
              ->withHeader('X-Content-Type-Options','nosniff');
});

$app->get('/api/shopping',function(Request $rq,Response $rs) use($auth,$shopping) {$auth->requireSession();return jsonResponse($rs,['items'=>$shopping->saved()]);});
$app->get('/api/search',function(Request $rq,Response $rs) use($auth,$shopping) {
    $auth->requireSession();$q=(string)($rq->getQueryParams()['q']??'');
    if(mb_strlen($q)>120)throw new ApiException('Pesquisa muito longa.');
    return jsonResponse($rs,['products'=>$shopping->search($q)]);
});
$app->put('/api/shopping',function(Request $rq,Response $rs) use($auth,$shopping) {
    $auth->requireSession(true);$data=bodyData($rq);
    if(!isset($data['items'])||!is_array($data['items']))throw new ApiException('Lista inválida.');
    return jsonResponse($rs,['items'=>$shopping->replace($data['items'])]);
});
$app->patch('/api/shopping',function(Request $rq,Response $rs) use($auth,$shopping) {
    $auth->requireSession(true);
    return jsonResponse($rs,$shopping->applyChanges(bodyData($rq)));
});
$app->patch('/api/shopping/{id}/purchase',function(Request $rq,Response $rs,array $args) use($auth,$shopping) {
    $auth->requireSession(true);$data=bodyData($rq);
    if(!array_key_exists('bought',$data)||!is_bool($data['bought']))throw new ApiException('Estado inválido.');
    $shopping->purchase(idParam($args['id']),$data['bought']);return jsonResponse($rs,['ok'=>true]);
});
$app->post('/api/shopping/restore-all',function(Request $rq,Response $rs) use($auth,$shopping) {
    $auth->requireSession(true);$shopping->restoreAll();return jsonResponse($rs,['ok'=>true]);
});
$app->delete('/api/shopping',function(Request $rq,Response $rs) use($auth,$shopping) {
    $auth->requireSession(true);$shopping->clear();return jsonResponse($rs,['ok'=>true]);
});
$app->get('/api/purchases', function(Request $rq,Response $rs) use($auth,$purchases) {
    $auth->requireSession();
    $months=(string)($rq->getQueryParams()['months']??'3');
    if(!in_array($months,['1','3','6','9','12'],true)) throw new ApiException('Período inválido.');
    return jsonResponse($rs,$purchases->report((int)$months));
});
$app->get('/api/purchases/{id}', function(Request $rq,Response $rs,array $args) use($auth,$purchases) {
    $auth->requireSession();
    return jsonResponse($rs,['purchase'=>$purchases->detail(idParam($args['id']))]);
});
$app->post('/api/purchases', function(Request $rq,Response $rs) use($auth,$purchases) {
    $session=$auth->requireSession(true);
    $data=bodyData($rq);
    if(!array_key_exists('actual_total',$data)) throw new ApiException('Informe o valor total pago.');
    return jsonResponse($rs,['purchase'=>$purchases->close($data['actual_total'],$data['note']??'',(string)$session['username'])],201);
});

$app->get('/api/markets',function(Request $rq,Response $rs) use($auth,$sourcesAdmin) {
    $auth->requireSession();return jsonResponse($rs,['markets'=>$sourcesAdmin->markets()]);
});
$app->post('/api/sources',function(Request $rq,Response $rs) use($auth,$sourcesAdmin) {
    $auth->requireSession(true);return jsonResponse($rs,['source'=>$sourcesAdmin->save(bodyData($rq))],201);
});
$app->put('/api/sources/{uid}',function(Request $rq,Response $rs,array $args) use($auth,$sourcesAdmin) {
    $auth->requireSession(true);return jsonResponse($rs,['source'=>$sourcesAdmin->save(bodyData($rq),$args['uid'])]);
});
$app->delete('/api/sources/{uid}',function(Request $rq,Response $rs,array $args) use($auth,$sourcesAdmin) {
    $auth->requireSession(true);$sourcesAdmin->delete($args['uid']);return jsonResponse($rs,['ok'=>true]);
});
$app->get('/api/sources',function(Request $rq,Response $rs) use($auth,$jobs) {
    $auth->requireSession();return jsonResponse($rs,['sources'=>$jobs->sources()]);
});
$app->get('/api/jobs',function(Request $rq,Response $rs) use($auth,$jobs) {
    $auth->requireSession();return jsonResponse($rs,$jobs->recent());
});
$app->post('/api/sources/{uid}/run',function(Request $rq,Response $rs,array $args) use($auth,$jobs) {
    $auth->requireSession(true);
    if(!preg_match('/^[a-f0-9-]{36}$/iD',$args['uid']))throw new ApiException('Fonte inválida.');
    return jsonResponse($rs,['job_id'=>$jobs->manual($args['uid'])],202);
});
$app->post('/api/markets/{market}/run',function(Request $rq,Response $rs,array $args) use($auth,$jobs) {
    $auth->requireSession(true);
    return jsonResponse($rs,['job_ids'=>$jobs->manualMarket($args['market'])],202);
});
$app->post('/api/jobs/run-all',function(Request $rq,Response $rs) use($auth,$jobs) {
    $auth->requireSession(true);
    return jsonResponse($rs,['job_ids'=>$jobs->manualAll()],202);
});
$app->post('/api/jobs/pause',function(Request $rq,Response $rs) use($auth,$jobs) {
    $auth->requireSession(true);
    $data=bodyData($rq);
    if(!isset($data['paused'])||!is_bool($data['paused']))throw new ApiException('Estado inválido.');
    $jobs->pause($data['paused']);return jsonResponse($rs,['paused'=>$jobs->queuePaused()]);
});
$app->post('/api/jobs/{id}/retry',function(Request $rq,Response $rs,array $args) use($auth,$jobs) {
    $auth->requireSession(true);return jsonResponse($rs,['job_id'=>$jobs->retry(idParam($args['id']))],202);
});

$app->get('/api/health',function(Request $rq,Response $rs) use($db) {
    $db->query('SELECT 1');return jsonResponse($rs,['status'=>'ok']);
});

// CORS não habilitado: aplicação e API sempre na mesma origem.
$errors=$app->addErrorMiddleware(false,true,true);
$errors->setDefaultErrorHandler(function(Request $rq, \Throwable $e) use($app): Response {
    if($e instanceof ApiException){$status=$e->httpStatus;$message=$e->getMessage();}
    elseif($e instanceof \Slim\Exception\HttpNotFoundException) {$status=404;$message='Rota não encontrada.';}
    elseif($e instanceof \Slim\Exception\HttpMethodNotAllowedException) {$status=405;$message='Método não permitido.';}
    else{$status=500;$message='Ocorreu um erro interno. Consulte os logs do servidor.';error_log((string)$e);}
    return jsonResponse($app->getResponseFactory()->createResponse(),['error'=>$message],$status);
});
$app->run();
