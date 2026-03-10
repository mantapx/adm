<?php
/* ==========================================================
 * Simple MySQL Manager — Ultra-Compat (PHP 5.2 → 8.x)
 * © REDROOM
 * Fitur:
 * - Anti indexing (X-Robots-Tag + meta robots)
 * - Driver DB dinamis: mysqli → PDO(mysql) → mysql_*
 * - Tahan disable_functions via safe_call()
 * - Inline edit (prepared jika ada; jika tidak, escape aman)
 * - Tanpa fitur PHP >5.2 (tidak ada ??, closure, array deref)
 * ========================================================== */

// --- Matikan notice untuk PHP lawas agar UI bersih (opsional) ---
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
@error_reporting(E_ALL);

// --- Session aman + tahan disable_functions ---
if (!function_exists('safe_call')) {
  function safe_call($fn) {
    static $disabled = null;
    if ($disabled === null) {
      $d1 = @ini_get('disable_functions');
      $d2 = @ini_get('suhosin.executor.func.blacklist'); // beberapa server lama
      $list = strtolower(trim($d1 . ',' . $d2, " \t\n\r\0\x0B,"));
      $disabled = array();
      if ($list !== '') {
        $parts = explode(',', $list);
        foreach ($parts as $f) {
          $f = trim($f);
          if ($f !== '') $disabled[$f] = true;
        }
      }
    }
    return (is_string($fn) && function_exists($fn) && !isset($disabled[strtolower($fn)]));
  }
}
if ((function_exists('session_id') && session_id() === '') && safe_call('session_start')) { @session_start(); }

// --- Anti-indexing (header sebelum output) ---
if (safe_call('header')) {
  @header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex', true);
}

// --- Polyfill minimal json_encode untuk PHP 5.2 jika ext/json tak ada ---
if (!function_exists('json_encode')) {
  function json_encode($v) {
    if ($v === null) return 'null';
    if ($v === true) return 'true';
    if ($v === false) return 'false';
    if (is_numeric($v)) return (string)$v;
    if (is_string($v)) return '"'.str_replace(
      array("\\","\"","\n","\r","\t"),
      array("\\\\","\\\"","\\n","\\r","\\t"),
      $v
    ).'"';
    if (is_array($v)) {
      $isAssoc = false;
      $i = 0; foreach ($v as $k=>$vv){ if ($k !== $i++){ $isAssoc = true; break; } }
      $out = array();
      if ($isAssoc) { foreach ($v as $k=>$vv){ $out[] = json_encode((string)$k).':'.json_encode($vv);} return '{'.implode(',', $out).'}'; }
      else { foreach ($v as $vv){ $out[] = json_encode($vv);} return '['.implode(',', $out).']'; }
    }
    if (is_object($v)) { return json_encode(get_object_vars($v)); }
    return 'null';
  }
}

// --- Helpers umum ---
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function is_ident($s){ return is_string($s) && preg_match('/^[A-Za-z0-9_]+$/', $s); }
function bt($s){ if (!is_ident($s)) return null; return '`' . str_replace('`','``',$s) . '`'; }
function redirect_back($keep) {
  $path = isset($_SERVER["REQUEST_URI"]) ? parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH) : '';
  $qs = ($keep && is_array($keep)) ? ('?' . http_build_query($keep)) : '';
  if (safe_call('header')) { @header("Location: ".$path.$qs); }
  exit;
}
function server_node_name(){
  $h = (safe_call('php_uname')) ? @php_uname('n') : '';
  if (!$h || strtolower($h) === 'localhost') {
    $h2 = (safe_call('gethostname')) ? @gethostname() : '';
    if ($h2) $h = $h2;
  }
  if (!$h) {
    $ua = (safe_call('php_uname')) ? @php_uname() : '';
    if ($ua && preg_match('/\b([A-Za-z0-9.-]+\.[A-Za-z]{2,})\b/', (string)$ua, $m)) { $h = $m[1]; }
  }
  if (!$h) { $h = (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '(unknown)'); }
  return $h ? preg_replace('/[^A-Za-z0-9.\-]/', '', $h) : '(unknown)';
}
function extract_table_from_select($q){
  if (!is_string($q) || $q==='') return null;
  if (!preg_match('/\bfrom\s+`?([A-Za-z0-9_]+)`?/i', $q, $m)) return null;
  return $m[1];
}
function has_limit_clause($q){ return (bool)preg_match('/\blimit\b/i', (string)$q); }

// Pager helper (tanpa closure)
function build_pager_links($baseParams, $page, $total_pages, $param_name){
  if ($total_pages < 1) $total_pages = 1;
  $page = max(1, min($total_pages, (int)$page));

  $html = '<div class="flex flex-wrap items-center justify-center gap-2 my-3">';

  // builder link kecil
  $mk = function($p, $label, $cls) use ($baseParams, $param_name) {
    $params = $baseParams;
    $params[$param_name] = $p;
    $url = (isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '').'?'.http_build_query($params);
    $lab = $label ? $label : (string)$p;
    return '<a class="px-3 py-1 rounded-xl border border-slate-800 text-slate-200 hover:bg-slate-800 '.$cls.'" href="'.h($url).'">'.$lab.'</a>';
  };

  if ($page>1){ $html .= $mk(1,'« First',''); $html .= $mk($page-1,'‹ Prev',''); }

  $start = max(1,$page-2); $end=min($total_pages,$page+2);
  if ($start>1) $html .= '<span class="text-slate-400 px-1">…</span>';
  for($i=$start;$i<=$end;$i++){
    if ($i==$page) $html .= '<span class="px-3 py-1 rounded-xl bg-emerald-700/30 border border-emerald-700 text-emerald-200">'.$i.'</span>';
    else $html .= $mk($i,null,'');
  }
  if ($end<$total_pages) $html .= '<span class="text-slate-400 px-1">…</span>';

  if ($page<$total_pages){ $html .= $mk($page+1,'Next ›',''); $html .= $mk($total_pages,'Last »',''); }

  $html .= '</div>';
  return $html;
}

function has_creds($host,$user){ return (is_string($host)&&$host!==''&&is_string($user)&&$user!==''); }

// --- Driver DB Abstraksi (mysqli → PDO → mysql_*) ---
class DB {
  public $driver;       // 'mysqli' | 'pdo' | 'mysql'
  public $link;         // resource/obj
  public $last_error;   // string
  public $db;           // nama db aktif

  function __construct($host,$user,$pass,$db=null){
    $this->driver = null; $this->link = null; $this->last_error=''; $this->db = $db;

    // 1) mysqli
    if (class_exists('mysqli')) {
      $m = @new mysqli($host,$user,$pass,$db);
      if (isset($m->connect_errno) && $m->connect_errno) {
        $this->last_error = $m->connect_error;
      } else {
        if (method_exists($m, 'set_charset')) { @$m->set_charset('utf8mb4'); }
        $this->driver='mysqli'; $this->link=$m; return;
      }
    }

    // 2) PDO mysql
    if (class_exists('PDO')) {
      try {
        $dsn = 'mysql:host='.$host.($db?';dbname='.$db:'').';charset=utf8';
        $options = array(
          PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
          PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        );
        $pdo = new PDO($dsn, $user, $pass, $options);
        $this->driver='pdo'; $this->link=$pdo; return;
      } catch (Exception $e) {
        $this->last_error = $e->getMessage();
      }
    }

    // 3) mysql_* (PHP 5.x lama; hilang di PHP 7+)
    if (function_exists('mysql_connect')) {
      $conn = @mysql_connect($host,$user,$pass);
      if ($conn) {
        if ($db) { if (!@mysql_select_db($db,$conn)) { $this->last_error = @mysql_error($conn); } }
        $this->driver='mysql'; $this->link=$conn; return;
      } else { $this->last_error = @mysql_error(); }
    }

    if (!$this->driver) { if (!$this->last_error) $this->last_error = 'No MySQL driver available (mysqli/PDO/mysql_* not found).'; }
  }

  function close(){
    if ($this->driver==='mysqli' && $this->link){ @$this->link->close(); }
    if ($this->driver==='pdo'){ $this->link = null; }
    if ($this->driver==='mysql' && $this->link){ @mysql_close($this->link); }
  }

  function query($sql){
    if ($this->driver==='mysqli') {
      $r = @$this->link->query($sql); if ($r===false) $this->last_error = $this->link->error; return $r;
    }
    if ($this->driver==='pdo') {
      try { return $this->link->query($sql); } catch (Exception $e){ $this->last_error = $e->getMessage(); return false; }
    }
    if ($this->driver==='mysql') {
      $r = @mysql_query($sql, $this->link); if ($r===false) $this->last_error = @mysql_error($this->link); return $r;
    }
    $this->last_error = 'No driver.'; return false;
  }

  function escape($s){
    if ($s===null) return 'NULL';
    if ($this->driver==='mysqli') return "'".@$this->link->real_escape_string($s)."'";
    if ($this->driver==='pdo')    return "'".str_replace("'", "''", $s)."'";
    if ($this->driver==='mysql')  return "'".@mysql_real_escape_string($s, $this->link)."'";
    return "'".str_replace(array("\\","'"), array("\\\\","\\'"), $s)."'";
  }

  function fetch_all_assoc($res){
    $rows = array();
    if (!$res) return $rows;

    if ($this->driver==='mysqli') {
      if (method_exists($res, 'fetch_assoc')) {
        while ($row = @$res->fetch_assoc()) { $rows[] = $row; }
      }
      return $rows;
    }
    if ($this->driver==='pdo') {
      if (method_exists($res, 'fetchAll')) {
        try { $rows = $res->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) { $rows = array(); }
      }
      return $rows;
    }
    if ($this->driver==='mysql')  {
      while($row=@mysql_fetch_assoc($res)){ $rows[]=$row; }
      return $rows;
    }
    return $rows;
  }

  // Prepared UPDATE sederhana (mysqli/pdo) → boolean
  function prepared_update_one($table, $col, $val, $keycol, $keyval){
    if (!is_ident($table) || !is_ident($col) || !is_ident($keycol)) return array(false,'Invalid identifier');
    if ($this->driver==='mysqli') {
      $sql = "UPDATE ".bt($table)." SET ".bt($col)."=? WHERE ".bt($keycol)."=? LIMIT 1";
      $stmt = @$this->link->prepare($sql);
      if (!$stmt) return array(false, $this->link->error);
      @$stmt->bind_param("ss", $val, $keyval);
      $ok = @$stmt->execute(); $err = @$stmt->error; if (method_exists($stmt,'close')) @$stmt->close();
      return array($ok, $ok ? null : $err);
    }
    if ($this->driver==='pdo') {
      try{
        $sql = "UPDATE ".bt($table)." SET ".bt($col)."=? WHERE ".bt($keycol)."=? LIMIT 1";
        $stmt = $this->link->prepare($sql);
        $ok = $stmt->execute(array($val, $keyval));
        return array($ok, $ok? null : 'execute failed');
      }catch(Exception $e){ return array(false, $e->getMessage()); }
    }
    // fallback mysql_* tanpa prepared: sanitize manual
    $sql = "UPDATE ".bt($table)." SET ".bt($col)."=".$this->escape($val)." WHERE ".bt($keycol)."=".$this->escape($keyval)." LIMIT 1";
    $r = $this->query($sql);
    return array($r ? true : false, $r ? null : $this->last_error);
  }
}

// --- Creds (simpen di session) ---
$DEFAULT_HOST = ""; $DEFAULT_USER = ""; $DEFAULT_PASS = "";
if (isset($_POST['setcred'])) {
  $_SESSION['db_host'] = trim((string)(isset($_POST['db_host']) ? $_POST['db_host'] : ''));
  $_SESSION['db_user'] = trim((string)(isset($_POST['db_user']) ? $_POST['db_user'] : ''));
  $_SESSION['db_pass'] = (string)(isset($_POST['db_pass']) ? $_POST['db_pass'] : '');
  $keep = $_GET; redirect_back($keep);
}
$db_host = isset($_SESSION['db_host']) ? $_SESSION['db_host'] : (isset($_GET['db_host']) ? $_GET['db_host'] : $DEFAULT_HOST);
$db_user = isset($_SESSION['db_user']) ? $_SESSION['db_user'] : (isset($_GET['db_user']) ? $_GET['db_user'] : $DEFAULT_USER);
$db_pass = isset($_SESSION['db_pass']) ? $_SESSION['db_pass'] : (isset($_GET['db_pass']) ? $_GET['db_pass'] : $DEFAULT_PASS);
$cred_params = array('db_host'=>$db_host,'db_user'=>$db_user,'db_pass'=>$db_pass);

// --- UI state ---
$CARD_W = 'max-w-[720px]';
$db_selected    = (isset($_GET['db'])    && $_GET['db']    !== '') ? (string)$_GET['db']    : '';
$table_selected = (isset($_GET['table']) && $_GET['table'] !== '') ? (string)$_GET['table'] : '';
$view           = isset($_GET['view']) ? (string)$_GET['view'] : '';

$flash=''; $flash_class='';
function flash_and_back($msg,$cls,$keep){
  global $cred_params;
  if (!is_array($keep)) $keep = array();
  $keep = array_merge($_GET, $keep, $cred_params, array('_flash'=>$msg,'_fclass'=>$cls));
  redirect_back($keep);
}
if (isset($_GET['_flash']))  $flash = (string)$_GET['_flash'];
if (isset($_GET['_fclass'])) $flash_class = (string)$_GET['_fclass'];

// --- Aksi inline update ---
if (isset($_POST['action']) && $_POST['action']==='update_cell') {
  $db    = (string)(isset($_POST['db']) ? $_POST['db'] : '');
  $table = (string)(isset($_POST['table']) ? $_POST['table'] : '');
  $col   = (string)(isset($_POST['col']) ? $_POST['col'] : '');
  $keycol= (string)(isset($_POST['keycol']) ? $_POST['keycol'] : '');
  $keyval= (string)(isset($_POST['keyval']) ? $_POST['keyval'] : '');
  $newval= (string)(isset($_POST['newval']) ? $_POST['newval'] : '');

  if (!is_ident($db) || !is_ident($table) || !is_ident($col) || !is_ident($keycol)) {
    flash_and_back('Invalid identifier','error', array('db'=>$db,'table'=>$table,'view'=>'browse'));
  } else {
    if (!has_creds($db_host,$db_user)) {
      flash_and_back('Credential belum diisi','error', array('db'=>$db,'table'=>$table,'view'=>'browse'));
    }
    $dbc = new DB($db_host,$db_user,$db_pass,$db);
    if (!$dbc->driver) {
      flash_and_back('Connect failed: '.$dbc->last_error,'error', array('db'=>$db,'table'=>$table,'view'=>'browse'));
    }
    $ret = $dbc->prepared_update_one($table,$col,$newval,$keycol,$keyval);
    $ok  = $ret[0]; $err = $ret[1];
    $dbc->close();
    if (!$ok) { flash_and_back('Update failed: '.$err,'error', array('db'=>$db,'table'=>$table,'view'=>'browse')); }
    flash_and_back('Updated: '.$table.'.'.$col,'success', array('db'=>$db,'table'=>$table,'view'=>'browse'));
  }
}

// --- Load list database & table ---
$databases = array(); $tables = array(); $conn_error = '';
if (has_creds($db_host,$db_user)) {
  $db0 = new DB($db_host,$db_user,$db_pass);
  if ($db0->driver) {
    $r = $db0->query("SHOW DATABASES");
    if ($r) {
      $rows = $db0->fetch_all_assoc($r);
      for ($i=0;$i<count($rows);$i++){
        $vals = array_values($rows[$i]);
        $databases[] = isset($vals[0]) ? $vals[0] : '';
      }
    }
    $db0->close();
  } else { $conn_error = $db0->last_error; }

  if ($db_selected && is_ident($db_selected)) {
    $db1 = new DB($db_host,$db_user,$db_pass,$db_selected);
    if ($db1->driver) {
      $r = $db1->query("SHOW TABLES");
      if ($r) {
        $rows = $db1->fetch_all_assoc($r);
        for ($i=0;$i<count($rows);$i++){
          $vals = array_values($rows[$i]);
          $tables[] = isset($vals[0]) ? $vals[0] : '';
        }
      }
      $db1->close();
    }
  }
}

// --- Helper cari primary key ---
function find_row_key_driver($host,$user,$pass,$db,$table){
  $dbc = new DB($host,$user,$pass,$db);
  if (!$dbc->driver) return null;

  $sql = "SHOW KEYS FROM ".bt($table)." WHERE Key_name='PRIMARY'";
  $res = $dbc->query($sql);
  $cols = array();
  if ($res) {
    $rows = $dbc->fetch_all_assoc($res);
    foreach ($rows as $r){ if (isset($r['Column_name'])) $cols[] = $r['Column_name']; }
  }
  if (count($cols)===1){ $dbc->close(); return $cols[0]; }

  $sql2 = "SHOW KEYS FROM ".bt($table)." WHERE Non_unique=0";
  $res2 = $dbc->query($sql2);
  $map = array();
  if ($res2) {
    $rows = $dbc->fetch_all_assoc($res2);
    foreach ($rows as $r){
      $k = isset($r['Key_name'])?$r['Key_name']:'';
      if (!isset($map[$k])) $map[$k] = array();
      $map[$k][] = isset($r['Column_name'])?$r['Column_name']:null;
    }
    foreach ($map as $k=>$c){ if (count($c)===1){ $dbc->close(); return $c[0]; } }
  }
  $dbc->close();
  return null;
}

// --- BROWSE view (pager) ---
$browse_html = '';
if ($db_selected && $table_selected && $view==='browse' && is_ident($db_selected) && is_ident($table_selected)) {
  if (!has_creds($db_host,$db_user)) {
    $browse_html .= '<div class="p-4 rounded-2xl border border-red-500/30 bg-red-900/20 text-red-200">Cred belum diisi. Isi Host/User di sidebar lalu Save & Use.</div>';
  } else {
    $dbB = new DB($db_host,$db_user,$db_pass,$db_selected);
    if ($dbB->driver) {
      $limit = isset($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 20;
      $page  = isset($_GET['page'])  ? max(1, (int)$_GET['page']) : 1;
      $offset = ($page - 1) * $limit;

      $total_rows = 0; $total_pages = 1;
      $cnt = $dbB->query("SELECT COUNT(*) AS c FROM ".bt($table_selected));
      if ($cnt) {
        $rowsc = $dbB->fetch_all_assoc($cnt);
        $total_rows = (int)(isset($rowsc[0]['c']) ? $rowsc[0]['c'] : 0);
        $total_pages = max(1, (int)ceil($total_rows/$limit));
      }

      $keycol = find_row_key_driver($db_host,$db_user,$db_pass,$db_selected,$table_selected);
      $cols = array();
      $resC = $dbB->query("SHOW COLUMNS FROM ".bt($table_selected));
      if ($resC){
        $rcols = $dbB->fetch_all_assoc($resC);
        foreach($rcols as $c){ if (isset($c['Field'])) $cols[]=$c['Field']; }
      }

      $res = $dbB->query("SELECT * FROM ".bt($table_selected)." LIMIT ".(int)$limit." OFFSET ".(int)$offset);

      $browse_html .= '<div class="rounded-2xl p-4 ring-1 ring-slate-800 bg-slate-900/40 '.$CARD_W.' mx-auto">';
      $browse_html .= '<div class="flex items-baseline justify-between gap-3 mb-2">';
      $browse_html .= '<h2 class="text-slate-100 text-lg font-semibold">Browse: '.h($db_selected).'.'.h($table_selected).'</h2>';
      $browse_html .= '<div class="text-slate-400 text-sm">Rows: '.h($total_rows).' | Page '.h($page).' / '.h($total_pages).'</div>';
      $browse_html .= '</div>';

      if (!$keycol) $browse_html .= '<div class="text-slate-400 text-sm mb-2">Edit per cell off: tabel tidak punya PRIMARY KEY/UNIQUE 1 kolom.</div>';
      else $browse_html .= '<div class="text-slate-400 text-sm mb-2">Editable key: <code class="text-emerald-300">'.h($keycol).'</code> (klik cell untuk edit).</div>';

      $base = array_merge($cred_params, array('db'=>$db_selected,'table'=>$table_selected,'view'=>'browse','limit'=>$limit));
      $browse_html .= build_pager_links($base, $page, $total_pages, 'page');

      if ($res) {
        $rows = $dbB->fetch_all_assoc($res);
        $browse_html .= '<div class="overflow-x-auto overflow-y-auto max-h-[60vh] rounded-xl ring-1 ring-slate-800">';
        $browse_html .= '<table class="min-w-max text-sm text-slate-200 whitespace-nowrap">';
        $browse_html .= '<thead class="sticky top-0 bg-slate-900/90 backdrop-blur border-b border-slate-800"><tr>';
        for ($i=0;$i<count($cols);$i++){ $browse_html .= '<th class="text-left font-semibold px-3 py-2">'.h($cols[$i]).'</th>'; }
        $browse_html .= '</tr></thead><tbody class="divide-y divide-slate-800">';
        for ($ri=0;$ri<count($rows);$ri++) {
          $row = $rows[$ri];
          $browse_html .= '<tr class="hover:bg-slate-800/40">';
          for ($ci=0;$ci<count($cols);$ci++) {
            $c = $cols[$ci];
            $val = isset($row[$c]) ? (string)$row[$c] : '';
            $disp = ($val==='') ? '<span class="text-slate-500">(empty)</span>' : h($val);
            if ($keycol && isset($row[$keycol])) {
              $kval = (string)$row[$keycol];
              $attr = 'data-db="'.h($db_selected).'" data-table="'.h($table_selected).'" data-col="'.h($c).'" data-keycol="'.h($keycol).'" data-keyval="'.h($kval).'" data-old="'.h($val).'"';
              $browse_html .= '<td class="px-3 py-2 align-top"><span class="border-b border-dashed border-emerald-500/50 hover:border-emerald-300 cursor-text cell-edit" '.$attr.'>'.$disp.'</span></td>';
            } else {
              $browse_html .= '<td class="px-3 py-2 align-top">'.$disp.'</td>';
            }
          }
          $browse_html .= '</tr>';
        }
        $browse_html .= '</tbody></table></div>';
      } else {
        $browse_html .= '<div class="text-slate-400 text-center py-6">Tidak bisa load rows.</div>';
      }
      $browse_html .= build_pager_links($base, $page, $total_pages, 'page');
      $browse_html .= '</div>';
      $dbB->close();
    } else {
      $browse_html .= '<div class="p-4 rounded-2xl ring-1 ring-red-700 bg-red-900/20 text-red-200 '.$CARD_W.' mx-auto">Connect failed: '.h($conn_error).'</div>';
    }
  }
}

// --- OUTPUT (query bebas + pager untuk SELECT tanpa LIMIT) ---
$output_html = ''; $copy_text='';
function run_query_with_pager($db,$rawQuery,$qlimit,$qpage){
  global $db_host,$db_user,$db_pass;
  $ret = array('html'=>'','copy'=>'');
  $dbc = new DB($db_host,$db_user,$db_pass,$db);
  if (!$dbc->driver) { $ret['html'] = '<pre class="p-4 rounded-xl bg-slate-900/60 ring-1 ring-slate-800 text-red-200">Connect failed: '.h($dbc->last_error).'</pre>'; return $ret; }

  $tableFrom = extract_table_from_select($rawQuery);
  $isSelect = (bool)preg_match('/^\s*select\b/i', $rawQuery);
  $qoffset = ($qpage-1)*$qlimit;

  $shouldPaginate = $isSelect && $tableFrom && !has_limit_clause($rawQuery);
  $total_rows = 0; $total_pages = 1;
  $effectiveQuery = $rawQuery;
  if ($shouldPaginate) {
    $cnt = $dbc->query("SELECT COUNT(*) AS c FROM ".bt($tableFrom));
    if ($cnt) {
      $rowsc = $dbc->fetch_all_assoc($cnt);
      $total_rows = (int)(isset($rowsc[0]['c'])?$rowsc[0]['c']:0);
      $total_pages = max(1,(int)ceil($total_rows/$qlimit));
    }
    $effectiveQuery = $rawQuery." LIMIT ".$qlimit." OFFSET ".$qoffset;
  }

  $res = $dbc->query($effectiveQuery);
  if ($res===false) {
    $ret['html'] = '<pre class="p-4 rounded-xl bg-slate-900/60 ring-1 ring-slate-800 text-red-200">Query error: '.h($dbc->last_error).'</pre>';
    $dbc->close(); return $ret;
  }

  if ($isSelect) {
    $cols = array();
    if ($tableFrom) {
      $colres = $dbc->query("SHOW COLUMNS FROM ".bt($tableFrom));
      if ($colres){
        $rcols = $dbc->fetch_all_assoc($colres);
        foreach ($rcols as $c){ if (isset($c['Field'])) $cols[] = $c['Field']; }
      }
    }
    $rows = $dbc->fetch_all_assoc($res);

    if ($shouldPaginate){
      $base = array_merge($_GET, array('db'=>$db,'query'=>$rawQuery,'qlimit'=>$qlimit));
      $ret['html'] .= build_pager_links($base, $qpage, $total_pages, 'qpage');
      $ret['html'] .= '<div class="text-slate-400 text-sm text-center mb-2">Rows: '.h($total_rows).' | Page '.h($qpage).' / '.h($total_pages).'</div>';
    }

    $ret['html'] .= '<div class="overflow-x-auto overflow-y-auto max-h-[60vh] rounded-xl ring-1 ring-slate-800">';
    $ret['html'] .= '<table class="min-w-max text-sm text-slate-200 whitespace-nowrap">';
    $ret['html'] .= '<thead class="sticky top-0 bg-slate-900/90 backdrop-blur border-b border-slate-800"><tr>';
    if (!empty($cols)) {
      for ($i=0;$i<count($cols);$i++){ $ret['html'] .= '<th class="text-left font-semibold px-3 py-2">'.h($cols[$i]).'</th>'; }
    } elseif (!empty($rows)) {
      $cols = array_keys($rows[0]);
      for ($i=0;$i<count($cols);$i++){ $ret['html'] .= '<th class="text-left font-semibold px-3 py-2">'.h($cols[$i]).'</th>'; }
    } else {
      $ret['html'] .= '<th class="px-3 py-2">(no columns)</th>';
    }
    $ret['html'] .= '</tr></thead><tbody class="divide-y divide-slate-800">';

    $keycol = null;
    if ($tableFrom){ $keycol = find_row_key_driver($db_host,$db_user,$db_pass,$db,$tableFrom); }

    if (empty($rows)) {
      $ret['html'] .= '<tr><td class="px-3 py-4 text-slate-400">Tidak ada data.</td></tr>';
    } else {
      for ($ri=0;$ri<count($rows);$ri++) {
        $r = $rows[$ri];
        $ret['html'] .= '<tr class="hover:bg-slate-800/40">';
        for ($ci=0;$ci<count($cols);$ci++) {
          $c = $cols[$ci];
          $val = isset($r[$c]) ? (string)$r[$c] : '';
          $disp = ($val==='') ? '<span class="text-slate-500">(empty)</span>' : h($val);
          if ($tableFrom && $keycol && array_key_exists($keycol,$r)) {
            $attr = 'data-db="'.h($db).'" data-table="'.h($tableFrom).'" data-col="'.h($c).'" data-keycol="'.h($keycol).'" data-keyval="'.h((string)$r[$keycol]).'" data-old="'.h($val).'"';
            $ret['html'] .= '<td class="px-3 py-2 align-top"><span class="border-b border-dashed border-emerald-500/50 hover:border-emerald-300 cursor-text cell-edit" '.$attr.'>'.$disp.'</span></td>';
          } else {
            $ret['html'] .= '<td class="px-3 py-2 align-top">'.$disp.'</td>';
          }
        }
        $ret['html'] .= '</tr>';
      }
    }
    $ret['html'] .= '</tbody></table></div>';

    if ($shouldPaginate){
      $base = array_merge($_GET, array('db'=>$db,'query'=>$rawQuery,'qlimit'=>$qlimit));
      $ret['html'] .= build_pager_links($base, $qpage, $total_pages, 'qpage');
    }
    $ret['copy'] = json_encode($rows);
  } else {
    if (is_object($res) || is_array($res)) {
      $ret['html'] = '<pre class="p-4 rounded-xl bg-slate-900/60 ring-1 ring-slate-800">'.h(print_r($res,true)).'</pre>';
      $ret['copy'] = 'ok';
    } else {
      $txt = ($res ? 'True' : 'False');
      $ret['html'] = '<pre class="p-4 rounded-xl bg-slate-900/60 ring-1 ring-slate-800">'.$txt.'</pre>';
      $ret['copy'] = $txt;
    }
  }
  $dbc->close();
  return $ret;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Simple MySQL Manager</title>

  <!-- Anti-index meta -->
  <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
  <meta name="googlebot" content="noindex, nofollow, noarchive, nosnippet, noimageindex">

  <!-- Fonts & Tailwind -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = { theme: { extend: { fontFamily: { sans: ['Inter','ui-sans-serif','system-ui','-apple-system','Segoe UI','Roboto','Ubuntu','Cantarell','Noto Sans','Helvetica','Arial'] } } }, darkMode: 'class' }
  </script>
  <style>
    html,body{height:100%}
    body{-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
    textarea.ie-input{ resize:vertical; }
  </style>
</head>
<body class="min-h-screen bg-black text-slate-100">
  <div class="w-full max-w-[1440px] mx-auto px-5 py-6">
    <div class="text-center mb-4">
      <div class="py-2 text-slate-400 font-mono">Simple MySQL Manager</div>
      <div class="py-1">
        <?php
          $showDbHref = h((isset($_SERVER["REQUEST_URI"]) ? parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH) : '')) . '?' . h(http_build_query($cred_params));
          echo '<a class="mx-2 text-emerald-300 hover:text-emerald-200" href="'.$showDbHref.'">Show Databases</a>';
          if ($db_selected) {
            $params_show_tables = array_merge($cred_params, array('db'=>$db_selected, 'query'=>'show tables'));
            echo ' <a class="mx-2 text-emerald-300 hover:text-emerald-200" href="'.h((isset($_SERVER['PHP_SELF'])?$_SERVER['PHP_SELF']:'').'?'.http_build_query($params_show_tables)).'">Show Tables</a>';
            echo ' <a class="mx-2 text-emerald-300 hover:text-emerald-200" href="'.h((isset($_SERVER['PHP_SELF'])?$_SERVER['PHP_SELF']:'').'?'.http_build_query(array_merge($cred_params, array('db'=>$db_selected,'view'=>'browse')))).'">Browse</a>';
          }
        ?>
        <div class="py-1 text-slate-400 text-sm">
          <span class="text-emerald-300"><?php echo h(server_node_name()); ?></span>
        </div>
      </div>
    </div>

    <div class="rounded-2xl shadow-2xl bg-slate-900/40 backdrop-blur ring-1 ring-slate-800">
      <div class="px-6">
        <?php
          if ($flash) {
            $cls = ($flash_class==='success')
              ? 'ring-emerald-700 bg-emerald-900/20 text-emerald-200'
              : 'ring-red-700 bg-red-900/20 text-red-200';
            echo '<div class="mt-4 p-3 rounded-xl ring-1 '.$cls.' '.$CARD_W.' mx-auto">'.h($flash).'</div>';
          }
          if ($conn_error) {
            echo '<div class="mt-4 p-3 rounded-xl ring-1 ring-red-700 bg-red-900/20 text-red-200 '.$CARD_W.' mx-auto">Connect failed: '.h($conn_error).'</div>';
          }
        ?>
      </div>

      <div class="grid grid-cols-[360px_1fr] gap-5 p-6 xl:grid-cols-[360px_1fr] lg:grid-cols-[320px_1fr]">
        <!-- Sidebar -->
        <aside class="space-y-4">
          <div class="p-4 rounded-2xl bg-slate-900/40 ring-1 ring-slate-800">
            <h3 class="font-semibold mb-3">Connection</h3>
            <form method="POST" action="?<?php echo h(http_build_query($_GET)); ?>" class="space-y-3">
              <input type="hidden" name="setcred" value="1">
              <div>
                <label class="block text-sm text-slate-400 mb-1">Host</label>
                <input type="text" name="db_host" value="<?php echo h($db_host); ?>" class="w-full rounded-lg border border-slate-800 bg-slate-900 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-400/30">
              </div>
              <div>
                <label class="block text-sm text-slate-400 mb-1">User</label>
                <input type="text" name="db_user" value="<?php echo h($db_user); ?>" class="w-full rounded-lg border border-slate-800 bg-slate-900 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-400/30">
              </div>
              <div>
                <label class="block text-sm text-slate-400 mb-1">Pass</label>
                <input type="password" name="db_pass" value="" placeholder="(disembunyikan)" class="w-full rounded-lg border border-slate-800 bg-slate-900 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-400/30">
                <p class="text-xs text-slate-500 mt-1">Password tidak ditampilkan lagi. Isi ulang kalau mau ganti.</p>
              </div>
              <div class="text-center pt-2">
                <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-gradient-to-b from-emerald-400 to-emerald-600 text-slate-900 font-medium px-4 py-2 ring-1 ring-emerald-700 hover:brightness-105">Save &amp; Use</button>
              </div>
            </form>
          </div>

          <div class="p-4 rounded-2xl bg-slate-900/40 ring-1 ring-slate-800">
            <h3 class="font-semibold mb-3">Databases</h3>
            <div class="max-h-[320px] overflow-auto space-y-1">
              <?php
                if (count($databases) === 0) {
                  echo '<div class="text-slate-400 text-sm">Belum ada data (cek credential / koneksi).</div>';
                } else {
                  for ($i=0;$i<count($databases);$i++) {
                    $d = $databases[$i];
                    $isActive = ($db_selected === $d) ? 'bg-slate-800/60 text-emerald-200 ring-1 ring-emerald-700' : 'hover:bg-slate-800/40';
                    $link = (isset($_SERVER['PHP_SELF'])?$_SERVER['PHP_SELF']:'') . '?' . http_build_query(array_merge($cred_params, array('db'=>$d)));
                    echo '<a class="block px-3 py-2 rounded-lg '.$isActive.'" href="'.h($link).'">'.h($d).'</a>';
                  }
                }
              ?>
            </div>
          </div>

          <?php if ($db_selected): ?>
          <div class="p-4 rounded-2xl bg-slate-900/40 ring-1 ring-slate-800">
            <h3 class="font-semibold mb-3">Tables (<?php echo h($db_selected); ?>)</h3>
            <div class="max-h-[420px] overflow-auto space-y-1">
              <?php
                if (count($tables) === 0) {
                  echo '<div class="text-slate-400 text-sm">Tidak ada tabel / tidak bisa load tabel.</div>';
                } else {
                  for ($i=0;$i<count($tables);$i++) {
                    $t = $tables[$i];
                    $isActive = ($table_selected === $t) ? 'bg-slate-800/60 text-emerald-200 ring-1 ring-emerald-700' : 'hover:bg-slate-800/40';
                    $link = (isset($_SERVER['PHP_SELF'])?$_SERVER['PHP_SELF']:'') . '?' . http_build_query(array_merge($cred_params, array('db'=>$db_selected,'table'=>$t,'view'=>'browse')));
                    echo '<a class="block px-3 py-2 rounded-lg '.$isActive.'" href="'.h($link).'">'.h($t).'</a>';
                  }
                }
              ?>
            </div>
            <p class="text-xs text-slate-500 mt-2">Klik tabel untuk browse + edit per cell (butuh PK/Unique 1 kolom).</p>
          </div>
          <?php endif; ?>
        </aside>

        <!-- Content -->
        <main class="space-y-4">
          <div class="p-4 rounded-2xl bg-slate-900/40 ring-1 ring-slate-800 <?php echo $CARD_W; ?> mx-auto">
            <h2 class="font-semibold text-lg mb-3">Query</h2>
            <form method="GET" class="space-y-3">
              <input type="hidden" name="db_host" value="<?php echo h($db_host); ?>">
              <input type="hidden" name="db_user" value="<?php echo h($db_user); ?>">
              <input type="hidden" name="db_pass" value="<?php echo h($db_pass); ?>">

              <div>
                <label class="block text-sm text-slate-400 mb-1">DB</label>
                <input type="text" name="db" value="<?php echo h($db_selected); ?>" class="w-full rounded-lg border border-slate-800 bg-slate-900 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-400/30">
              </div>

              <div>
                <label class="block text-sm text-slate-400 mb-1">Query</label>
                <input type="text" name="query" value="<?php echo h(isset($_GET['query'])?$_GET['query']:''); ?>" class="w-full rounded-lg border border-slate-800 bg-slate-900 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-400/30" placeholder="select * from users limit 10">
              </div>

              <div class="grid grid-cols-3 gap-3">
                <div>
                  <label class="block text-sm text-slate-400 mb-1">qLimit</label>
                  <input type="number" min="1" max="200" name="qlimit" value="<?php echo h((string)(isset($_GET['qlimit'])?$_GET['qlimit']:20)); ?>" class="w-full rounded-lg border border-slate-800 bg-slate-900 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-400/30">
                </div>
                <div>
                  <label class="block text-sm text-slate-400 mb-1">qPage</label>
                  <input type="number" min="1" name="qpage" value="<?php echo h((string)(isset($_GET['qpage'])?$_GET['qpage']:1)); ?>" class="w-full rounded-lg border border-slate-800 bg-slate-900 px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-400/30">
                </div>
                <div class="flex items-end">
                  <button class="inline-flex items-center justify-center rounded-lg bg-gradient-to-b from-emerald-400 to-emerald-600 text-slate-900 font-medium px-6 py-2 ring-1 ring-emerald-700 hover:brightness-105 w-full" type="submit">Run</button>
                </div>
              </div>

              <p class="text-sm text-slate-400">Tips: pilih DB di sidebar ⇒ default query: <code class="text-emerald-300">show tables</code>. Klik “select” akan auto query <code class="text-emerald-300">select * from table limit 10</code>. Untuk SELECT tanpa LIMIT gunakan qLimit/qPage.</p>
            </form>
          </div>

          <?php echo $browse_html; ?>

          <div class="p-4 rounded-2xl bg-slate-900/40 ring-1 ring-slate-800 <?php echo $CARD_W; ?> mx-auto">
            <div class="flex items-center justify-center gap-2 mb-2">
              <h2 class="font-semibold">Output</h2>
              <span class="text-slate-500">|</span>
              <a href="#" onclick="return copyClipboard()" class="text-emerald-300 hover:text-emerald-200">Copy to Clipboard</a>
            </div>
            <?php
              $out=''; $copy='';
              if ($db_selected==='') {
                if (!has_creds($db_host,$db_user)) {
                  $out = '<pre class="p-4 rounded-xl bg-slate-900/60 ring-1 ring-slate-800 text-emerald-200">Cred belum diisi. Isi Host/User (sidebar) lalu klik Save &amp; Use.</pre>';
                  $copy = 'cred empty';
                } else {
                  $db0 = new DB($db_host,$db_user,$db_pass);
                  if (!$db0->driver) {
                    $out = '<pre class="p-4 rounded-xl bg-slate-900/60 ring-1 ring-slate-800 text-red-200">Connect failed: '.h($db0->last_error).'</pre>';
                  } else {
                    $r = $db0->query('SHOW DATABASES');
                    if (!$r) {
                      $out = '<pre class="p-4 rounded-xl bg-slate-900/60 ring-1 ring-slate-800 text-red-200">Query error: '.h($db0->last_error).'</pre>';
                    } else {
                      $rows = $db0->fetch_all_assoc($r);
                      $lines=array();
                      for ($i=0;$i<count($rows);$i++){
                        $vals = array_values($rows[$i]);
                        $name = isset($vals[0]) ? $vals[0] : '';
                        $params = array_merge($cred_params,array('db'=>$name));
                        $lines[]=' <a class="text-emerald-300 hover:text-emerald-200" href="'.h((isset($_SERVER['PHP_SELF'])?$_SERVER['PHP_SELF']:'').'?'.http_build_query($params)).'">set</a> '.h($name);
                      }
                      $out = '<pre class="p-4 rounded-xl bg-slate-900/60 ring-1 ring-slate-800">'.implode("\n",$lines).'</pre>';
                      $copy = strip_tags(implode("\r\n",$lines));
                    }
                    $db0->close();
                  }
                }
              } else {
                if (!has_creds($db_host,$db_user)) {
                  $out = '<pre class="p-4 rounded-xl bg-slate-900/60 ring-1 ring-slate-800 text-emerald-200">Cred belum diisi. Isi Host/User (sidebar) lalu klik Save &amp; Use.</pre>';
                  $copy = 'cred empty';
                } else {
                  $rawQuery = isset($_GET['query']) ? trim((string)$_GET['query']) : 'show tables';
                  $qlimit = isset($_GET['qlimit']) ? max(1, min(200, (int)$_GET['qlimit'])) : 20;
                  $qpage  = isset($_GET['qpage']) ? max(1, (int)$_GET['qpage']) : 1;
                  $res = run_query_with_pager($db_selected,$rawQuery,$qlimit,$qpage);
                  $out = $res['html']; $copy = $res['copy'];
                }
              }
              echo '<div class="overflow-x-auto overflow-y-auto max-h-[60vh] rounded-xl">';
              echo $out ? $out : '<pre class="p-4 rounded-xl bg-slate-900/60 ring-1 ring-slate-800 text-slate-300">(no output)</pre>';
              echo '</div>';
            ?>
            <textarea id="output" class="hidden"><?php echo h($copy); ?></textarea>
          </div>
        </main>
      </div>

      <!-- FOOTER -->
      <footer class="px-6 pb-6 pt-2">
        <div class="max-w-[720px] mx-auto text-center text-sm text-slate-500">
          &copy; <?php echo date('Y'); ?> <span class="text-emerald-300">SimplE</span>. All rights reserved.
        </div>
      </footer>
    </div>
  </div>

<script>
function copyClipboard(){
  var copyText = document.getElementById("output");
  if (!copyText) return false;
  if (copyText.select) { copyText.select(); try{ if (copyText.setSelectionRange) copyText.setSelectionRange(0, 99999); }catch(e){} }
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(copyText.value);
  } else {
    try { document.execCommand('copy'); } catch(e){}
  }
  return false;
}

// Inline editor
document.addEventListener('click', function(e){
  var t = e.target || e.srcElement;
  var span = t && t.closest ? t.closest('.cell-edit') : null;
  if (!span) return;
  if (span.dataset && span.dataset.editing === '1') return;
  if (span.dataset) span.dataset.editing = '1';

  var db     = span.getAttribute('data-db');
  var table  = span.getAttribute('data-table');
  var col    = span.getAttribute('data-col');
  var keycol = span.getAttribute('data-keycol');
  var keyval = span.getAttribute('data-keyval');
  var oldv   = span.getAttribute('data-old') || '';

  var originalHTML = span.innerHTML;

  var isLong = oldv.length > 80 || /\n/.test(oldv);
  var editor = document.createElement(isLong ? 'textarea' : 'input');
  editor.className = 'ie-input w-full min-w-[120px] bg-slate-900 border border-slate-800 rounded-lg px-2 py-1 text-slate-100 focus:outline-none focus:ring-2 focus:ring-emerald-400/30';
  editor.value = oldv;
  editor.style.width = Math.max(120, span.clientWidth - 10) + 'px';
  if (isLong) editor.style.minHeight = '64px';

  var wrapper = document.createElement('div'); wrapper.className = 'inline-editor flex items-center gap-2';
  var actions = document.createElement('div'); actions.className = 'ie-actions flex items-center gap-2';

  var btnSave = document.createElement('button'); btnSave.type='button'; btnSave.textContent='Save';
  btnSave.className='px-3 py-1 rounded-md ring-1 ring-emerald-700 bg-emerald-600 text-slate-900 font-medium';
  var btnCancel = document.createElement('button'); btnCancel.type='button'; btnCancel.textContent='Cancel';
  btnCancel.className='px-3 py-1 rounded-md ring-1 ring-slate-800 bg-slate-800 text-slate-200';

  actions.appendChild(btnSave); actions.appendChild(btnCancel);
  wrapper.appendChild(editor); wrapper.appendChild(actions);

  span.innerHTML = ''; span.appendChild(wrapper);
  if (editor.focus) editor.focus(); if (editor.tagName === 'INPUT' && editor.select) editor.select();

  function restore(){ if (span.dataset) span.dataset.editing='0'; span.innerHTML = originalHTML; }
  function lockUI(v){ btnSave.disabled = btnCancel.disabled = !!v; if (v){ btnSave.classList.add('opacity-60'); btnCancel.classList.add('opacity-60'); } else { btnSave.classList.remove('opacity-60'); btnCancel.classList.remove('opacity-60'); } }

  function doSave(value){
    var form = new FormData();
    form.append('action','update_cell'); form.append('db',db);
    form.append('table',table); form.append('col',col);
    form.append('keycol',keycol); form.append('keyval',keyval); form.append('newval',value);

    lockUI(true);
    fetch(location.href, { method:'POST', body:form, credentials:'same-origin' })
      .then(function(){ location.href = location.href; })
      .catch(function(err){ alert('Gagal menyimpan: '+err); lockUI(false); restore(); });
  }

  btnSave.addEventListener('click', function(){ doSave(editor.value); });
  btnCancel.addEventListener('click', restore);

  editor.addEventListener('keydown', function(ev){
    ev = ev || window.event;
    if (ev.key === 'Enter' && editor.tagName === 'INPUT'){ if (ev.preventDefault) ev.preventDefault(); doSave(editor.value); }
    if (ev.key === 'Escape'){ if (ev.preventDefault) ev.preventDefault(); restore(); }
  });
  editor.addEventListener('blur', function(){ if (editor.tagName === 'INPUT') doSave(editor.value); });
});
</script>
</body>
</html>
