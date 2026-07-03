<?php
header('Content-Type: text/html; charset=UTF-8');
define('DB_PATH', __DIR__ . '/pdf_database.db');
define('PER_PAGE', 15);

function getDB() {
    if (!file_exists(DB_PATH)) return null;
    $db = new SQLite3(DB_PATH, SQLITE3_OPEN_READONLY);
    $db->exec("PRAGMA cache_size=5000");
    return $db;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function getStats($db) {
    return [
        'total'     => $db->querySingle("SELECT COUNT(*) FROM documents"),
        'divisions' => $db->querySingle("SELECT COUNT(DISTINCT division) FROM documents WHERE division IS NOT NULL"),
        'districts' => $db->querySingle("SELECT COUNT(DISTINCT district) FROM documents WHERE district IS NOT NULL"),
        'upazilas'  => $db->querySingle("SELECT COUNT(DISTINCT upazila) FROM documents WHERE upazila IS NOT NULL"),
    ];
}

function getDivisions($db) {
    $r = $db->query("SELECT DISTINCT division FROM documents WHERE division IS NOT NULL ORDER BY division");
    $list = [];
    while ($row = $r->fetchArray(SQLITE3_NUM)) $list[] = $row[0];
    return $list;
}

function getDistricts($db, $div) {
    $s = $db->prepare("SELECT DISTINCT district FROM documents WHERE division=:d AND district IS NOT NULL ORDER BY district");
    $s->bindValue(':d', $div);
    $r = $s->execute();
    $list = [];
    while ($row = $r->fetchArray(SQLITE3_NUM)) $list[] = $row[0];
    return $list;
}

function getUpazilas($db, $dis) {
    $s = $db->prepare("SELECT DISTINCT upazila FROM documents WHERE district=:d AND upazila IS NOT NULL ORDER BY upazila");
    $s->bindValue(':d', $dis);
    $r = $s->execute();
    $list = [];
    while ($row = $r->fetchArray(SQLITE3_NUM)) $list[] = $row[0];
    return $list;
}

function search($db, $kw, $div, $dis, $upa, $page) {
    $offset = ($page - 1) * PER_PAGE;
    $where = []; $p = [];
    if ($kw)  { $where[] = "content LIKE :kw"; $p[':kw'] = "%$kw%"; }
    if ($div) { $where[] = "division=:div";    $p[':div'] = $div; }
    if ($dis) { $where[] = "district=:dis";    $p[':dis'] = $dis; }
    if ($upa) { $where[] = "upazila=:upa";     $p[':upa'] = $upa; }
    $w = $where ? 'WHERE '.implode(' AND ', $where) : '';
    $s = $db->prepare("SELECT id,pdf_filename,zip_file,division,district,upazila,word_count,page_count,SUBSTR(content,1,250) AS preview FROM documents $w ORDER BY division,district,upazila,pdf_filename LIMIT :lim OFFSET :off");
    foreach ($p as $k=>$v) $s->bindValue($k,$v);
    $s->bindValue(':lim', PER_PAGE, SQLITE3_INTEGER);
    $s->bindValue(':off', $offset,  SQLITE3_INTEGER);
    $r = $s->execute();
    $rows = [];
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
    return $rows;
}

function countResults($db, $kw, $div, $dis, $upa) {
    $where = []; $p = [];
    if ($kw)  { $where[] = "content LIKE :kw"; $p[':kw'] = "%$kw%"; }
    if ($div) { $where[] = "division=:div";    $p[':div'] = $div; }
    if ($dis) { $where[] = "district=:dis";    $p[':dis'] = $dis; }
    if ($upa) { $where[] = "upazila=:upa";     $p[':upa'] = $upa; }
    $w = $where ? 'WHERE '.implode(' AND ', $where) : '';
    $s = $db->prepare("SELECT COUNT(*) FROM documents $w");
    foreach ($p as $k=>$v) $s->bindValue($k,$v);
    return (int)$s->execute()->fetchArray(SQLITE3_NUM)[0];
}

function getDoc($db, $id) {
    $s = $db->prepare("SELECT * FROM documents WHERE id=:id");
    $s->bindValue(':id',(int)$id,SQLITE3_INTEGER);
    $r = $s->execute()->fetchArray(SQLITE3_ASSOC);
    return $r ?: null;
}

// AJAX
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $db = getDB();
    if ($_GET['ajax']==='districts' && isset($_GET['division']) && $db)
        echo json_encode(getDistricts($db, $_GET['division']));
    elseif ($_GET['ajax']==='upazilas' && isset($_GET['district']) && $db)
        echo json_encode(getUpazilas($db, $_GET['district']));
    else echo '[]';
    exit;
}

$db      = getDB();
$kw      = trim($_GET['q']        ?? '');
$div     = trim($_GET['division'] ?? '');
$dis     = trim($_GET['district'] ?? '');
$upa     = trim($_GET['upazila']  ?? '');
$page    = max(1,(int)($_GET['page'] ?? 1));
$view_id = isset($_GET['view']) ? (int)$_GET['view'] : null;

$stats     = $db ? getStats($db) : [];
$divisions = $db ? getDivisions($db) : [];
$doc       = ($view_id && $db) ? getDoc($db,$view_id) : null;
$searching = $kw||$div||$dis||$upa;
$results   = [];
$total     = 0;

if ($searching && $db) {
    $results = search($db,$kw,$div,$dis,$upa,$page);
    $total   = countResults($db,$kw,$div,$dis,$upa);
}
$total_pages = $total ? (int)ceil($total/PER_PAGE) : 0;
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>PDF ডেটাবেজ সার্চ</title>
<style>
:root{--g:#1a7a4a;--gd:#145f39;--gl:#e8f5ee;--ink:#1c1c1c;--mut:#5a6472;--bdr:#d4dde6;--bg:#f5f7fa;--r:8px;--sh:0 2px 12px rgba(0,0,0,.08)}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Noto Sans Bengali',system-ui,sans-serif;background:var(--bg);color:var(--ink)}
header{background:linear-gradient(135deg,var(--g),var(--gd));color:#fff;padding:18px 24px}
header h1{font-size:1.3rem}
header p{font-size:.82rem;opacity:.8;margin-top:3px}
.stats{background:#fff;border-bottom:1px solid var(--bdr);display:flex;gap:28px;padding:10px 24px;flex-wrap:wrap}
.stat-n{font-size:1.15rem;font-weight:700;color:var(--g)}
.stat-l{font-size:.78rem;color:var(--mut);margin-left:5px}
.main{max-width:1000px;margin:28px auto;padding:0 20px}
.card{background:#fff;border-radius:var(--r);box-shadow:var(--sh);padding:24px;margin-bottom:22px}
.card h2{font-size:.95rem;color:var(--mut);margin-bottom:14px}
input[type=text]{width:100%;padding:11px 14px;border:2px solid var(--bdr);border-radius:var(--r);font-size:1rem;font-family:inherit;outline:none;margin-bottom:12px}
input[type=text]:focus{border-color:var(--g)}
.filters{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px}
@media(max-width:580px){.filters{grid-template-columns:1fr}}
select{width:100%;padding:10px 12px;border:2px solid var(--bdr);border-radius:var(--r);font-size:.92rem;font-family:inherit;background:#fff;outline:none}
select:focus{border-color:var(--g)}
.btns{display:flex;gap:10px}
.btn{padding:11px 24px;border:none;border-radius:var(--r);font-size:.95rem;font-family:inherit;font-weight:600;cursor:pointer}
.btn-p{background:var(--g);color:#fff}
.btn-p:hover{background:var(--gd)}
.btn-c{background:var(--bg);color:var(--mut);border:2px solid var(--bdr)}
.meta{color:var(--mut);font-size:.88rem;margin-bottom:14px}
.meta strong{color:var(--g)}
.rc{background:#fff;border-radius:var(--r);box-shadow:var(--sh);padding:18px 22px;margin-bottom:12px;border-left:4px solid var(--g)}
.rt{font-size:1rem;font-weight:700;color:var(--g);margin-bottom:6px}
.rt a{color:inherit;text-decoration:none}
.rt a:hover{text-decoration:underline}
.tags{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:8px}
.tag{background:var(--gl);color:var(--gd);padding:2px 10px;border-radius:20px;font-size:.78rem;font-weight:500}
.tag-z{background:#fff3e0;color:#e65100}
.preview{font-size:.88rem;color:var(--mut);line-height:1.7}
.rf{margin-top:10px;display:flex;justify-content:space-between;align-items:center;font-size:.8rem;color:var(--mut)}
.rf a{color:var(--g);text-decoration:none;font-weight:600}
.pages{display:flex;gap:5px;justify-content:center;margin-top:24px;flex-wrap:wrap}
.pages a,.pages span{display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:var(--r);font-size:.88rem;text-decoration:none;border:2px solid var(--bdr);color:var(--ink)}
.pages a:hover{border-color:var(--g);color:var(--g)}
.pages .cur{background:var(--g);color:#fff;border-color:var(--g)}
.empty{text-align:center;padding:50px 20px;color:var(--mut)}
.empty .ic{font-size:44px;margin-bottom:10px}
.dc{background:#fff;border-radius:var(--r);box-shadow:var(--sh);padding:28px}
.dc h2{margin-bottom:14px}
.content-box{background:var(--bg);border:1px solid var(--bdr);border-radius:var(--r);padding:20px;white-space:pre-wrap;line-height:2;font-size:.93rem;max-height:580px;overflow-y:auto}
.no-db{background:#fff8e1;border:2px dashed #f0a500;border-radius:var(--r);padding:36px;text-align:center}
.back{display:inline-block;margin-bottom:14px;color:var(--g);text-decoration:none}
</style>
</head>
<body>
<header>
  <h1>📄 PDF ডেটাবেজ সার্চ</h1>
  <p>বিভাগ · জেলা · উপজেলা ভিত্তিক বাংলা দলিল অনুসন্ধান</p>
</header>

<?php if($db && $stats): ?>
<div class="stats">
  <div><span class="stat-n"><?=number_format($stats['total'])?></span><span class="stat-l">ডকুমেন্ট</span></div>
  <div><span class="stat-n"><?=$stats['divisions']?></span><span class="stat-l">বিভাগ</span></div>
  <div><span class="stat-n"><?=$stats['districts']?></span><span class="stat-l">জেলা</span></div>
  <div><span class="stat-n"><?=$stats['upazilas']?></span><span class="stat-l">উপজেলা</span></div>
</div>
<?php endif; ?>

<div class="main">
<?php if(!$db): ?>
  <div class="no-db">
    <div style="font-size:44px">⚠️</div>
    <h2 style="margin:10px 0 6px">ডেটাবেজ পাওয়া যায়নি</h2>
    <p style="color:#666">GitHub Actions চালান এবং <code>pdf_database.db</code> আপলোড হওয়ার অপেক্ষা করুন।</p>
  </div>

<?php elseif($doc): ?>
  <a href="?" class="back">← সার্চে ফিরুন</a>
  <div class="dc">
    <h2><?=h($doc['pdf_filename'])?></h2>
    <div class="tags">
      <?php if($doc['division']): ?><span class="tag"><?=h($doc['division'])?></span><?php endif; ?>
      <?php if($doc['district']): ?><span class="tag"><?=h($doc['district'])?></span><?php endif; ?>
      <?php if($doc['upazila']):  ?><span class="tag"><?=h($doc['upazila'])?></span><?php endif; ?>
      <span class="tag tag-z"><?=h($doc['zip_file'])?></span>
    </div>
    <p style="color:var(--mut);font-size:.83rem;margin-bottom:14px">
      📄 <?=$doc['page_count']?> পৃষ্ঠা &nbsp;|&nbsp; 📝 <?=number_format($doc['word_count'])?> শব্দ
    </p>
    <div class="content-box"><?=h($doc['content'])?></div>
  </div>

<?php else: ?>
  <div class="card">
    <h2>🔍 অনুসন্ধান করুন</h2>
    <form method="GET">
      <input type="text" name="q" placeholder="যেকোনো শব্দ লিখুন…" value="<?=h($kw)?>">
      <div class="filters">
        <select name="division" id="sd" onchange="loadDis(this.value)">
          <option value="">— সব বিভাগ —</option>
          <?php foreach($divisions as $d): ?>
            <option value="<?=h($d)?>" <?=$div===$d?'selected':''?>><?=h($d)?></option>
          <?php endforeach; ?>
        </select>
        <select name="district" id="sds" onchange="loadUpa(this.value)">
          <option value="">— সব জেলা —</option>
          <?php if($dis): ?><option value="<?=h($dis)?>" selected><?=h($dis)?></option><?php endif; ?>
        </select>
        <select name="upazila" id="su">
          <option value="">— সব উপজেলা —</option>
          <?php if($upa): ?><option value="<?=h($upa)?>" selected><?=h($upa)?></option><?php endif; ?>
        </select>
      </div>
      <div class="btns">
        <button type="submit" class="btn btn-p">🔍 সার্চ</button>
        <a href="?" class="btn btn-c">✕ পরিষ্কার</a>
      </div>
    </form>
  </div>

  <?php if($searching): ?>
    <p class="meta"><strong><?=number_format($total)?></strong> টি ফলাফল
      <?php if($kw): ?> "<?=h($kw)?>" এর জন্য<?php endif; ?>
      <?php if($div||$dis||$upa): ?> — <?=h($div)?> <?=$dis?'› '.h($dis):''?> <?=$upa?'› '.h($upa):''?><?php endif; ?>
      <?php if($total_pages>1): ?>(পৃষ্ঠা <?=$page?>/<?=$total_pages?>)<?php endif; ?>
    </p>
    <?php if(empty($results)): ?>
      <div class="empty"><div class="ic">🔎</div><h3>কোনো ফলাফল নেই</h3><p>ভিন্ন শব্দ দিয়ে চেষ্টা করুন।</p></div>
    <?php else: ?>
      <?php foreach($results as $row): ?>
      <div class="rc">
        <div class="rt"><a href="?view=<?=$row['id']?>"><?=h($row['pdf_filename'])?></a></div>
        <div class="tags">
          <?php if($row['division']): ?><span class="tag"><?=h($row['division'])?></span><?php endif; ?>
          <?php if($row['district']): ?><span class="tag"><?=h($row['district'])?></span><?php endif; ?>
          <?php if($row['upazila']):  ?><span class="tag"><?=h($row['upazila'])?></span><?php endif; ?>
          <span class="tag tag-z"><?=h($row['zip_file'])?></span>
        </div>
        <?php if($row['preview']): ?><div class="preview"><?=h($row['preview'])?>…</div><?php endif; ?>
        <div class="rf">
          <span>📄 <?=$row['page_count']?> পৃষ্ঠা | 📝 <?=number_format($row['word_count'])?> শব্দ</span>
          <a href="?view=<?=$row['id']?>">পড়ুন →</a>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if($total_pages>1):
        $base='?q='.urlencode($kw).'&division='.urlencode($div).'&district='.urlencode($dis).'&upazila='.urlencode($upa); ?>
      <div class="pages">
        <?php if($page>1): ?><a href="<?=$base?>&page=<?=$page-1?>">‹</a><?php endif; ?>
        <?php for($p=max(1,$page-2);$p<=min($total_pages,$page+2);$p++): ?>
          <?php if($p===$page): ?><span class="cur"><?=$p?></span>
          <?php else: ?><a href="<?=$base?>&page=<?=$p?>"><?=$p?></a><?php endif; ?>
        <?php endfor; ?>
        <?php if($page<$total_pages): ?><a href="<?=$base?>&page=<?=$page+1?>">›</a><?php endif; ?>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  <?php else: ?>
    <div class="empty"><div class="ic">🏛️</div><h3>সার্চ শুরু করুন</h3><p>কীওয়ার্ড লিখুন অথবা বিভাগ বেছে নিন।</p></div>
  <?php endif; ?>
<?php endif; ?>
</div>

<script>
async function loadDis(div) {
  const sd=document.getElementById('sds'),su=document.getElementById('su');
  sd.innerHTML='<option value="">— লোড হচ্ছে… —</option>';
  su.innerHTML='<option value="">— সব উপজেলা —</option>';
  if(!div){sd.innerHTML='<option value="">— সব জেলা —</option>';return;}
  const data=await(await fetch('?ajax=districts&division='+encodeURIComponent(div))).json();
  sd.innerHTML='<option value="">— সব জেলা —</option>';
  data.forEach(d=>{const o=document.createElement('option');o.value=d;o.textContent=d;sd.appendChild(o);});
}
async function loadUpa(dis) {
  const su=document.getElementById('su');
  su.innerHTML='<option value="">— লোড হচ্ছে… —</option>';
  if(!dis){su.innerHTML='<option value="">— সব উপজেলা —</option>';return;}
  const data=await(await fetch('?ajax=upazilas&district='+encodeURIComponent(dis))).json();
  su.innerHTML='<option value="">— সব উপজেলা —</option>';
  data.forEach(u=>{const o=document.createElement('option');o.value=u;o.textContent=u;su.appendChild(o);});
}
window.addEventListener('DOMContentLoaded',()=>{
  const d='<?=h($div)?>',ds='<?=h($dis)?>',u='<?=h($upa)?>';
  if(d) loadDis(d).then(()=>{
    if(ds){document.getElementById('sds').value=ds;loadUpa(ds).then(()=>{if(u)document.getElementById('su').value=u;});}
  });
});
</script>
</body>
</html>
