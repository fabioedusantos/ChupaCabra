<?php
declare(strict_types=1);
/** Uso: php bin/create-users.php fabio outra-conta [ ... ]
 * Senhas via console sem aparecer na linha de comando; saída base64 para o .env.
 */
$users=[];
foreach(array_slice($argv,1) as $username) {
    if(!preg_match('/^[a-zA-Z0-9._@-]{2,80}$/D',$username)) {fwrite(STDERR,"Usuário inválido: $username\n");exit(1);}
    fwrite(STDERR,"Senha para $username: ");
    $interactive=function_exists('shell_exec') && trim((string)shell_exec('stty -g 2>/dev/null'))!=='';
    if($interactive) shell_exec('stty -echo');
    $password=rtrim((string)fgets(STDIN),"\r\n");
    if($interactive) shell_exec('stty echo');
    fwrite(STDERR,"\n");
    if(strlen($password)<12) {fwrite(STDERR,"Exija pelo menos 12 caracteres.\n");exit(1);}
    $users[$username]=password_hash($password,PASSWORD_ARGON2ID);
    unset($password);
}
if(!$users){fwrite(STDERR,"Informe pelo menos um nome de usuário.\n");exit(1);}
echo 'AUTH_USERS_B64='.base64_encode(json_encode($users,JSON_THROW_ON_ERROR)).PHP_EOL;
