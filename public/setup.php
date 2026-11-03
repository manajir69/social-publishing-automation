<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Database;
use App\Security\Csrf;

$db=Database::connection();
$exists=(int)$db->query('SELECT COUNT(*) FROM admins')->fetchColumn()>0;
$message='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&!$exists){
    Csrf::verify();
    $setupKey=(string)env('SETUP_KEY','');
    if($setupKey===''||!hash_equals($setupKey,(string)($_POST['setup_key']??'')))$message='Invalid setup key.';
    elseif(!filter_var($_POST['email']??'',FILTER_VALIDATE_EMAIL))$message='Enter a valid email.';
    elseif(strlen((string)($_POST['password']??''))<12)$message='Password must be at least 12 characters.';
    else{$q=$db->prepare('INSERT INTO admins(name,email,password_hash) VALUES(?,?,?)');$q->execute([trim((string)$_POST['name']),strtolower(trim((string)$_POST['email'])),password_hash((string)$_POST['password'],PASSWORD_DEFAULT)]);$exists=true;$message='Admin created. Delete setup.php now, then open index.php.';}
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Initial setup</title><link rel="stylesheet" href="assets/app.css"></head><body><main><div class="login panel"><h1>Initial admin setup</h1><?php if($message):?><p><?=e($message)?></p><?php endif?><?php if($exists):?><p>An administrator already exists. Delete <code>public/setup.php</code>.</p><?php else:?><form method="post"><?=Csrf::input()?><label>Setup key<input type="password" name="setup_key" required></label><label>Name<input name="name" required></label><label>Email<input type="email" name="email" required></label><label>Password (12+ characters)<input type="password" name="password" minlength="12" required></label><button>Create administrator</button></form><?php endif?></div></main></body></html>
