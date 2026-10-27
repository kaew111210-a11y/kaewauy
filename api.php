<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET,POST,OPTIONS');
if ($_SERVER['REQUEST_METHOD']==='OPTIONS') { http_response_code(204); exit; }
try {
  $pdo=new PDO('mysql:host=127.0.0.1;dbname=kannganngan;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
  $method=$_SERVER['REQUEST_METHOD'];
  if($method==='GET'){
    $accounts=$pdo->query('SELECT id,display_name AS name,progress FROM accounts ORDER BY id')->fetchAll();
    $tasks=$pdo->query("SELECT t.id,t.title,t.description,t.task_type AS type,t.progress,t.is_done AS done,a.display_name AS owner FROM tasks t JOIN accounts a ON a.id=t.account_id ORDER BY t.created_at DESC")->fetchAll();
    $history=$pdo->query("SELECT h.id,h.title,h.action,h.progress,h.created_at,a.display_name AS owner FROM task_history h JOIN accounts a ON a.id=h.account_id ORDER BY h.created_at DESC LIMIT 100")->fetchAll();
    foreach($tasks as &$t){$t['id']=(int)$t['id'];$t['progress']=(int)$t['progress'];$t['done']=(bool)$t['done'];} unset($t);
    foreach($accounts as &$a)$a['progress']=(int)$a['progress']; unset($a);
    out(['ok'=>true,'accounts'=>$accounts,'tasks'=>$tasks,'history'=>$history]);
  }
  if($method==='POST'){
    $p=json_decode(file_get_contents('php://input'),true)?:[]; $action=$p['action']??''; $pdo->beginTransaction();
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
function out(array $x):never{echo json_encode($x,JSON_UNESCAPED_UNICODE);exit;}
