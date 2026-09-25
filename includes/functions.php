<?php
function e($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function url(string $path=''):string{global $config;return rtrim($config['site_url'],'/').'/'.ltrim($path,'/');}
function csrf():string{if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));return $_SESSION['csrf'];}
function verify_csrf():void{if(!hash_equals($_SESSION['csrf']??'',$_POST['csrf']??'')){http_response_code(419);exit('Invalid request token.');}}
function user():?array{global $pdo;static $u=null;if($u!==null)return $u;if(empty($_SESSION['user_id']))return null;$s=$pdo->prepare('SELECT id,name,email,is_admin,active,created_at FROM users WHERE id=?');$s->execute([$_SESSION['user_id']]);$u=$s->fetch()?:null;return $u&&$u['active']?$u:null;}
function require_login():void{if(!user()){header('Location: '.url('login.php'));exit;}}
function is_admin():bool{$u=user();return $u&&(int)$u['is_admin']===1;}
function require_admin():void{if(!is_admin()){header('Location: '.url('admin/login.php'));exit;}}
function valid_page():int{$p=filter_input(INPUT_GET,'page',FILTER_VALIDATE_INT);return max(1,$p?:1);}
function selected($a,$b):string{return (string)$a===(string)$b?'selected':'';}
function page_links(int $page,int $pages,string $base):void{if($pages<2)return;echo '<nav class="pagination" aria-label="Pagination">';for($i=1;$i<=$pages;$i++){if($i===1||$i===$pages||abs($i-$page)<=2){$u=$base.(str_contains($base,'?')?'&':'?').'page='.$i;echo '<a href="'.e($u).'"'.($i===$page?' aria-current="page"':'').'>'.$i.'</a>';}}echo '</nav>';}
function anime_languages(int $id):array{global $pdo;$s=$pdo->prepare('SELECT language,COUNT(*) count FROM video_sources WHERE anime_id=? AND active=1 GROUP BY language ORDER BY language');$s->execute([$id]);return $s->fetchAll();}
function card(array $a):void{$langs=anime_languages((int)$a['id']);?><article class="card"><a href="<?=url('anime.php?id='.(int)$a['id'])?>"><img loading="lazy" src="<?=e($a['poster']?:'assets/placeholder.svg')?>" alt="<?=e($a['title'])?>" onerror="this.onerror=null;this.src='<?=e(url('assets/placeholder.svg'))?>'"><div class="card-body"><h3><?=e($a['title'])?></h3><small><?=e($a['type']?:'Anime')?> · <?=e($a['year']?:'—')?> · ★ <?=e($a['score']?:'—')?></small><?php if($langs):?><div class="badges"><?php foreach($langs as $l):?><span><?=e($l['language'])?></span><?php endforeach;?></div><?php endif;?></div></a></article><?php }
function safe_source(string $url):bool{$p=parse_url(trim($url));return !empty($p['scheme'])&&!empty($p['host'])&&in_array(strtolower($p['scheme']),['http','https'],true);}
