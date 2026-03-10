<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../auth/session.php';
requireLogin();
ini_set('display_errors', 0); error_reporting(0);

$pageTitle  = 'Import Excel';
$activePage = 'fees_import';
$db = getDB();
$importLog  = [];
$importDone = false;
$errors     = [];

$sheetGroupMap = [
    'CERT MAY-INTAKE'   => 1,
    'CERT SEPT-INTAKE'  => 2,
    'DIPLOMA SEC YR'    => 3,
    'DIPLOMA THIRD YR'  => 4,
];

function feeNormalizeMode(string $raw): string {
    $raw = strtolower(trim($raw));
    if (str_contains($raw,'mpesa') || str_contains($raw,'mpsa')) return 'mpesa';
    if (str_contains($raw,'helb'))    return 'helb';
    if (str_contains($raw,'bank') || str_contains($raw,'kcb')) return 'bank';
    if (str_contains($raw,'ecitizen')) return 'ecitizen';
    if (str_contains($raw,'smis'))    return 'smis';
    if (str_contains($raw,'receipt')) return 'receipted';
    if (str_contains($raw,'nairobi')) return 'nairobi_campus';
    return 'other';
}

function feeParseDate($val): ?string {
    if (!$val) return null;
    $v = trim((string)$val);
    if (preg_match('/^(\d{1,2})\.(\d{2})\.(\d{2,4})$/', $v, $m)) {
        $y = strlen($m[3])===2 ? '20'.$m[3] : $m[3];
        return $y.'-'.str_pad($m[2],2,'0',STR_PAD_LEFT).'-'.str_pad($m[1],2,'0',STR_PAD_LEFT);
    }
    $ts = strtotime($v);
    return $ts ? date('Y-m-d',$ts) : null;
}

function feeColToIdx(string $col): int {
    $col = strtoupper(preg_replace('/[^A-Za-z]/','',$col));
    $n = 0;
    foreach (str_split($col) as $ch) $n = $n*26+(ord($ch)-64);
    return $n-1;
}

function feePostPayment(PDO $db, int $fsid, float $amt, string $mode, $phone, $ref, $datePaid, int $adminId, bool $dry, array &$log): bool {
    if (!$fsid || $amt <= 0) return false;
    $m = feeNormalizeMode($mode);
    $d = feeParseDate($datePaid) ?? date('Y-m-d');
    $p = trim((string)$phone) ?: null;
    $r = strtoupper(trim((string)$ref)) ?: null;
    if ($r) {
        $dup = $db->prepare("SELECT payment_id FROM fee_payments WHERE fee_student_id=? AND reference=?");
        $dup->execute([$fsid,$r]);
        if ($dup->fetch()) { $log[]=["type"=>"skip","msg"=>"    ~ dup $r (KES ".number_format($amt).") skipped"]; return false; }
    }
    $log[]=["type"=>"pay","msg"=>"    + KES ".number_format($amt)." ".strtoupper($m)." ref=".($r??"—")." date=$d"];
    if (!$dry) $db->prepare("INSERT INTO fee_payments (fee_student_id,amount,mode,mpesa_number,reference,date_paid,posted_by) VALUES (?,?,?,?,?,?,?)")->execute([$fsid,$amt,$m,$p,$r,$d,$adminId]);
    return true;
}

function feeFlushStudent(PDO $db, ?array &$cur, int $groupId, bool $dry, array &$log, int &$sadd, int &$padd): void {
    if (!$cur) return;
    $sid = trim($cur['sid']);
    // SKIP: no ADM NO or no slash (invalid format)
    if (!$sid || strpos($sid,'/') === false) {
        $log[]=["type"=>"skip","msg"=>"  SKIP (no valid ADM NO): {$cur['name']}"];
        $cur = null; return;
    }
    if ($cur['fees'] <= 0) $cur['fees'] = $cur['default_fees'];
    $chk = $db->prepare("SELECT fee_student_id FROM fee_students WHERE student_id=?");
    $chk->execute([$sid]); $ex = $chk->fetch();
    if ($ex) {
        $cur['fsid'] = $ex['fee_student_id'];
        $log[]=["type"=>"info","msg"=>"  Existing: {$cur['name']} ($sid) — payments appended"];
    } else {
        if (!$dry) {
            $db->prepare("INSERT INTO fee_students (student_id,full_name,programme,group_id,total_fees) VALUES (?,?,?,?,?)")
               ->execute([$sid,$cur['name'],$cur['prog'],$groupId,$cur['fees']]);
            $cur['fsid'] = $db->lastInsertId();
        } else { $cur['fsid'] = 0; }
        $log[]=["type"=>"ok","msg"=>"  + {$cur['name']} ($sid) KES ".number_format($cur['fees'])];
        $sadd++;
    }
    foreach ($cur['pmts'] as $pp) {
        if (feePostPayment($db,$cur['fsid'],$pp['amt'],$pp['m'],$pp['p'],$pp['r'],$pp['d'],$cur['admin_id'],$dry,$log)) $padd++;
    }
    $cur = null;
}

function runFeeImport(string $path, PDO $db, array $sheetMap, int $adminId, bool $dry): array {
    $log = [];
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return ["log"=>[["type"=>"error","msg"=>"Cannot open file as ZIP/XLSX."]],"fatal"=>true];

    // Shared strings
    $ss = [];
    $ssRaw = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssRaw) {
        $ssRaw = preg_replace('/xmlns[^=]*="[^"]*"/i','',$ssRaw);
        $ssRaw = preg_replace('/<\?xml[^?]*\?>/i','',$ssRaw);
        $ssx = @simplexml_load_string($ssRaw);
        if ($ssx) foreach ($ssx->si as $si) { $t=''; foreach($si->r as $r) $t.=(string)$r->t; if($t==='') $t=(string)$si->t; $ss[]=$t; }
    }
    $log[]=["type"=>"info","msg"=>"Shared strings: ".count($ss)];

    // Sheet list via regex
    $wbRaw = $zip->getFromName('xl/workbook.xml');
    preg_match_all('/<sheet\s[^>]*name="([^"]+)"[^>]*r:id="([^"]+)"/i',$wbRaw,$sm,PREG_SET_ORDER);
    if (empty($sm)) { preg_match_all('/<sheet\s[^>]*r:id="([^"]+)"[^>]*name="([^"]+)"/i',$wbRaw,$m2,PREG_SET_ORDER); foreach($m2 as $m) $sm[]=['',$m[2],$m[1]]; }
    $log[]=["type"=>"info","msg"=>"Sheets: ".implode(', ',array_column($sm,1))];

    // Rels
    $relsRaw = $zip->getFromName('xl/_rels/workbook.xml.rels');
    preg_match_all('/Id="([^"]+)"[^>]*Target="([^"]+)"/i',$relsRaw,$rm,PREG_SET_ORDER);
    $ridMap=[]; foreach($rm as $r) $ridMap[$r[1]]='xl/'.$r[2];

    foreach ($sm as $s) {
        $name=trim($s[1]); $rid=$s[2]; $path2=$ridMap[$rid]??null;
        $gid=null;
        foreach($sheetMap as $k=>$v){ if(strtoupper(trim($name))===strtoupper(trim($k))){ $gid=$v; break; } }
        if(!$gid){ $log[]=["type"=>"skip","msg"=>"Sheet '$name' not mapped."]; continue; }
        if(!$path2){ $log[]=["type"=>"error","msg"=>"No path for '$name'"]; continue; }

        $sRaw=$zip->getFromName($path2);
        if(!$sRaw){ $log[]=["type"=>"error","msg"=>"Cannot read $path2"]; continue; }
        $sRaw=preg_replace('/xmlns[^=]*="[^"]*"/i','',$sRaw);
        $sRaw=preg_replace('/<\?xml[^?]*\?>/i','',$sRaw);
        $sx=@simplexml_load_string($sRaw);
        if(!$sx){ $log[]=["type"=>"error","msg"=>"Cannot parse XML for '$name'"]; continue; }

        $log[]=["type"=>"sheet","msg"=>"=== $name (Group $gid) ==="];
        $defaultFees = ($gid <= 2) ? 129000 : 201000;

        // Build rows array
        $rows=[];
        foreach($sx->sheetData->row as $row){
            $rn=(int)$row['r']; $rows[$rn]=[];
            foreach($row->c as $c){
                $ref=(string)$c['r']; preg_match('/^([A-Za-z]+)(\d+)$/',$ref,$mx);
                $ci=feeColToIdx($mx[1]); $t=(string)($c['t']??''); $v=(string)($c->v??'');
                if($t==='s') $rows[$rn][$ci]=$ss[(int)$v]??'';
                elseif($t==='inlineStr') $rows[$rn][$ci]=(string)($c->is->t??'');
                else $rows[$rn][$ci]=($v!=='')?$v:null;
            }
        }

        $cur=null; $sadd=0; $padd=0;

        foreach($rows as $rn=>$row){
            $A=trim((string)($row[0]??'')); $B=trim((string)($row[1]??'')); $C=trim((string)($row[2]??'')); $D=trim((string)($row[3]??''));
            $E=$row[4]; $F=$row[5];
            $H=trim((string)($row[7]??'')); $I=trim((string)($row[8]??'')); $J=trim((string)($row[9]??'')); $K=trim((string)($row[10]??''));

            if($A===''&&$B===''&&$F===null) continue;
            if(strtoupper($A)==='SN') continue;

            // Skip SUM/formula rows: no A, no B, E=big number, F has value but H+I+J+K all empty
            $eIsTotal = is_numeric($E) && floatval($E) > 50000;
            $isSumRow = ($A===''&&$B===''&&$eIsTotal&&$H===''&&$I===''&&$J===''&&$K==='');
            if($isSumRow){
                // Capture total fees if not set yet
                if($cur && $cur['fees']<=0) $cur['fees']=floatval($E);
                continue;
            }

            // New student row: col A is numeric serial, col B=name, col C=adm no
            $isStudent=false;
            if($A!==''&&$B!==''){ try{ intval(floatval($A)); $isStudent=($A>0||$A==='0')&&is_numeric($A); }catch(Exception $e){} }

            if($isStudent&&$B!==''&&$C!==''){
                feeFlushStudent($db,$cur,$gid,$dry,$log,$sadd,$padd);
                $cur=['name'=>strtoupper($B),'sid'=>$C,'prog'=>$D?:null,'fees'=>is_numeric($E)?floatval($E):0,'default_fees'=>$defaultFees,'pmts'=>[],'fsid'=>null,'admin_id'=>$adminId];
                if(is_numeric($F)&&floatval($F)>0) $cur['pmts'][]=['amt'=>floatval($F),'m'=>$H,'p'=>$I,'r'=>$K,'d'=>$J];
                continue;
            }

            if(!$cur) continue;

            // Capture total fees from any row in this block
            if($eIsTotal && $cur['fees']<=0) $cur['fees']=floatval($E);

            // Payment row: F has a number AND (has a ref OR has a date OR has a mode — not a bare SUM row)
            $fVal = is_numeric($F) ? floatval($F) : 0;
            $hasPaymentDetail = ($H!==''||$I!==''||$J!==''||$K!=='');
            if($fVal>0 && $hasPaymentDetail){
                $cur['pmts'][]=['amt'=>$fVal,'m'=>$H,'p'=>$I,'r'=>$K,'d'=>$J];
            }
        }
        feeFlushStudent($db,$cur,$gid,$dry,$log,$sadd,$padd);
        $v=$dry?'would be':'were';
        $log[]=["type"=>"summary","msg"=>"  → $sadd student(s) $v added, $padd payment(s) $v posted."];
    }

    $zip->close();
    return ["log"=>$log,"fatal"=>false];
}

if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_FILES['excel_file'])){
    $f=$_FILES['excel_file']; $dry=isset($_POST['dry_run']);
    if($f['error']!==UPLOAD_ERR_OK) $errors[]='Upload error '.$f['error'].'. Try again.';
    elseif(strtolower(pathinfo($f['name'],PATHINFO_EXTENSION))!=='xlsx') $errors[]='Only .xlsx files accepted.';
    elseif(!class_exists('ZipArchive')) $errors[]='ZipArchive not available. Enable php_zip in php.ini.';
    elseif(!function_exists('simplexml_load_string')) $errors[]='SimpleXML not available.';
    else{ $res=runFeeImport($f['tmp_name'],$db,$sheetGroupMap,$_SESSION['admin_id'],$dry); $importLog=$res['log']; $importDone=!$dry&&!$res['fatal']; }
}

include __DIR__ . '/partials/header.php';
?>
<style>
.import-wrap{max-width:680px}
.upload-zone{border:2px dashed var(--border);border-radius:12px;padding:40px;text-align:center;cursor:pointer;transition:border-color .2s,background .2s}
.upload-zone:hover,.upload-zone.drag{border-color:#d97706;background:rgba(217,119,6,.03)}
.log-box{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:16px 18px;font-family:'Space Mono',monospace;font-size:12px;max-height:460px;overflow-y:auto;margin-top:20px;line-height:1.7}
.log-ok{color:#16a34a}.log-pay{color:#2563eb}.log-info{color:var(--text-muted)}.log-skip{color:#d97706}.log-error{color:#dc2626;font-weight:700}.log-sheet{color:var(--text-primary);font-weight:700;margin-top:8px;border-top:1px solid var(--border);padding-top:8px}.log-summary{color:#7c3aed;font-weight:700}
</style>
<div class="page-header">
    <div><h1 class="page-title">📥 Import Excel</h1><p class="page-subtitle">Upload the fees Excel to bulk-import all students and payments</p></div>
    <a href="<?= APP_ROOT ?>/fees/students.php" class="btn btn-ghost">← Students</a>
</div>
<?php if(!empty($errors)): ?><div class="alert alert-error"><?php foreach($errors as $e): ?><div><?=htmlspecialchars($e)?></div><?php endforeach;?></div><?php endif;?>
<?php if($importDone): ?><div class="alert alert-success">✅ Import complete! <a href="<?= APP_ROOT ?>/fees/students.php">View students →</a></div><?php endif;?>
<div class="card import-wrap"><div class="card-body">
    <div style="background:rgba(217,119,6,.06);border:1px solid rgba(217,119,6,.2);border-radius:10px;padding:14px 16px;margin-bottom:20px;font-size:13px;line-height:1.65">
        <strong>Expected sheet names:</strong>
        <div style="margin-top:8px;display:flex;flex-direction:column;gap:4px">
            <?php foreach($sheetGroupMap as $k=>$v):?><div><code style="background:rgba(0,0,0,.08);padding:1px 6px;border-radius:4px"><?=$k?></code> → Group <?=$v?></div><?php endforeach;?>
        </div>
        <div style="margin-top:10px;color:var(--text-muted);font-size:12px">Duplicate transaction references skipped. Students without a valid ADM NO (e.g. 12822/25) are skipped. Safe to run multiple times.</div>
    </div>
    <form method="POST" enctype="multipart/form-data">
        <div style="margin-bottom:18px">
            <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--text-muted);margin-bottom:7px">Excel File (.xlsx) *</label>
            <div class="upload-zone" id="drop-zone" onclick="document.getElementById('excel_file').click()">
                <div style="font-size:36px;margin-bottom:10px">📊</div>
                <div id="file-label" style="font-size:15px;font-weight:600;color:var(--text-muted)">Click to choose .xlsx file</div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:5px">or drag and drop here</div>
                <input type="file" id="excel_file" name="excel_file" accept=".xlsx" style="display:none" onchange="showFN(this)">
            </div>
        </div>
        <label style="display:flex;align-items:center;gap:10px;font-size:13px;cursor:pointer;margin-bottom:20px;user-select:none">
            <input type="checkbox" name="dry_run" style="width:16px;height:16px;accent-color:#d97706">
            <strong>Dry Run</strong> — preview without saving anything
        </label>
        <button type="submit" class="btn btn-primary" style="width:100%;padding:13px;font-size:14px">📥 Run Import</button>
    </form>
</div></div>
<?php if(!empty($importLog)):?>
<div class="log-box">
    <div style="font-weight:700;margin-bottom:10px;font-size:13px">Import Log <?=!empty($_POST['dry_run'])?'<span style="color:#d97706">(DRY RUN — nothing saved)</span>':''?></div>
    <?php foreach($importLog as $e):?><div class="log-<?=htmlspecialchars($e['type'])?>"><?=htmlspecialchars($e['msg'])?></div><?php endforeach;?>
</div>
<?php endif;?>
<script>
function showFN(i){const l=document.getElementById('file-label');l.textContent=i.files[0]?i.files[0].name:'Click to choose .xlsx file';l.style.color=i.files[0]?'var(--text-primary)':'var(--text-muted)';}
const z=document.getElementById('drop-zone');
z.addEventListener('dragover',e=>{e.preventDefault();z.classList.add('drag')});
z.addEventListener('dragleave',()=>z.classList.remove('drag'));
z.addEventListener('drop',e=>{e.preventDefault();z.classList.remove('drag');const f=e.dataTransfer.files[0];if(f){document.getElementById('excel_file').files=e.dataTransfer.files;showFN(document.getElementById('excel_file'));}});
</script>
<?php include __DIR__ . '/partials/footer.php'; ?>
