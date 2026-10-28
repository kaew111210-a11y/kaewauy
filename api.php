<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET,POST,OPTIONS');
register_shutdown_function(function(){
  $error=error_get_last();
  if($error && in_array($error['type'],[E_ERROR,E_CORE_ERROR,E_COMPILE_ERROR,E_PARSE],true)){
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'PHP error: '.$error['message']],JSON_UNESCAPED_UNICODE);
  }
});
if ($_SERVER['REQUEST_METHOD']==='OPTIONS') { http_response_code(204); exit; }
try {
  $pdo=new PDO('mysql:host=127.0.0.1;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
  $pdo->exec("CREATE DATABASE IF NOT EXISTS kannganngan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
  $pdo->exec("USE kannganngan");
  $pdo->exec("CREATE TABLE IF NOT EXISTS accounts (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, display_name VARCHAR(50) NOT NULL UNIQUE, username VARCHAR(50) NOT NULL UNIQUE, password_hash CHAR(64) NOT NULL, progress TINYINT UNSIGNED NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, description TEXT, task_type ENUM('once','daily') NOT NULL DEFAULT 'once', account_id INT UNSIGNED NOT NULL, progress TINYINT UNSIGNED NOT NULL DEFAULT 0, is_done TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, CONSTRAINT fk_task_account_api FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS task_history (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, task_id BIGINT UNSIGNED NULL, title VARCHAR(255) NOT NULL, account_id INT UNSIGNED NOT NULL, action VARCHAR(50) NOT NULL, progress TINYINT UNSIGNED NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_history_account_api FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("INSERT IGNORE INTO accounts (display_name,username,password_hash) VALUES ('แก้ว','kaew',SHA2('kaew',256)),('อุ้ย','auy',SHA2('auy',256))");
  $method=$_SERVER['REQUEST_METHOD'];
  $payload=json_decode(file_get_contents('php://input'),true)?:[];
  if($method==='POST' && ($payload['action']??'')==='login'){
    $q=$pdo->prepare('SELECT id,display_name,username,password_hash FROM accounts WHERE username=? LIMIT 1');$q->execute([trim((string)($payload['username']??''))]);$account=$q->fetch();
    if(!$account || !hash_equals($account['password_hash'],hash('sha256',(string)($payload['password']??'')))){http_response_code(401);out(['ok'=>false,'error'=>'รหัสผู้ใช้หรือรหัสผ่านไม่ถูกต้อง']);}
    $_SESSION['account_id']=(int)$account['id'];$_SESSION['display_name']=$account['display_name'];out(['ok'=>true,'user'=>['name'=>$account['display_name'],'username'=>$account['username']]]);
  }
  if($method==='POST' && ($payload['action']??'')==='logout'){session_destroy();out(['ok'=>true]);}
  if($method==='GET'){
    $accounts=$pdo->query('SELECT id,display_name AS name,progress FROM accounts ORDER BY id')->fetchAll();
    $tasks=$pdo->query("SELECT t.id,t.title,t.description,t.task_type AS type,t.progress,t.is_done AS done,a.display_name AS owner FROM tasks t JOIN accounts a ON a.id=t.account_id ORDER BY t.created_at DESC")->fetchAll();
    $history=$pdo->query("SELECT h.id,h.title,h.action,h.progress,h.created_at,a.display_name AS owner FROM task_history h JOIN accounts a ON a.id=h.account_id ORDER BY h.created_at DESC LIMIT 100")->fetchAll();
    foreach($tasks as &$t){$t['id']=(int)$t['id'];$t['progress']=(int)$t['progress'];$t['done']=(bool)$t['done'];} unset($t);
    foreach($accounts as &$a)$a['progress']=(int)$a['progress']; unset($a);
    out(['ok'=>true,'accounts'=>$accounts,'tasks'=>$tasks,'history'=>$history]);
  }
  if($method==='POST'){
    $p=$payload; $action=$p['action']??''; $pdo->beginTransaction();
    if($action==='create'){
      $q=$pdo->prepare('SELECT id FROM accounts WHERE display_name=?');$q->execute([$p['owner']??'แก้ว']);$aid=$q->fetchColumn();
      $q=$pdo->prepare('INSERT INTO tasks(title,description,task_type,account_id,progress,is_done) VALUES(?,?,?,?,?,0)');$q->execute([trim($p['title']??''),$p['description']??'',($p['type']??'once')==='daily'?'daily':'once',$aid,min(100,max(0,(int)($p['progress']??0)))]);$tid=$pdo->lastInsertId();
      $q=$pdo->prepare('INSERT INTO task_history(task_id,title,account_id,action,progress) VALUES(?,?,?,?,?)');$q->execute([$tid,$p['title'],$aid,'สร้างงาน',0]);
    } elseif($action==='update'){
      $q=$pdo->prepare('SELECT account_id,title FROM tasks WHERE id=?');$q->execute([(int)$p['id']]);$old=$q->fetch();$prog=min(100,max(0,(int)($p['progress']??0)));$done=$prog>=100?1:0;
      $q=$pdo->prepare('UPDATE tasks SET progress=?,is_done=? WHERE id=?');$q->execute([$prog,$done,(int)$p['id']]);$q=$pdo->prepare('INSERT INTO task_history(task_id,title,account_id,action,progress) VALUES(?,?,?,?,?)');$q->execute([$p['id'],$old['title'],$old['account_id'],$done?'ทำเสร็จแล้ว':'อัปเดตความคืบหน้า',$prog]);
    } elseif($action==='account_progress'){
      $q=$pdo->prepare('UPDATE accounts SET progress=? WHERE display_name=?');$q->execute([min(100,max(0,(int)$p['progress'])),$p['owner']]);
    } elseif($action==='delete'){$q=$pdo->prepare('DELETE FROM tasks WHERE id=?');$q->execute([(int)$p['id']]);}
    else throw new Exception('ไม่พบคำสั่ง');
    $pdo->commit();out(['ok'=>true]);
  }
  http_response_code(405);out(['ok'=>false,'error'=>'method']);
} catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();http_response_code(500);out(['ok'=>false,'error'=>$e->getMessage()]);}
function out(array $x){
  echo json_encode($x, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}
