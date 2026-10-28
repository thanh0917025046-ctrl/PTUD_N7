<?php
if (!defined('APP_ENTRY')) { http_response_code(403); exit; }
include_once __DIR__ . '/../Controller/cDieuChuyen.php';
$c = new clsCDieuChuyen(); $error = '';
$posted = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($posted && ($_POST['uc_action'] ?? '') === 'save_transfer') {
    $res = $c->luuPhieu($_POST, (int)$_SESSION['user_id']);
    if ($res['ok']) { setFlash('success', $res['message']); header('Location: index.php?page=dieuchuyen'); exit; }
    $error = $res['message'];
}
// Recover only scalar fields; malformed request arrays must not crash the View.
$values = [];
foreach (['MaPhieuDC','MaKhoXuat','MaKhoNhap','GhiChu'] as $key) $values[$key] = is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
$dcPost = [];
if (is_array($_POST['MaHang'] ?? null)) {
    foreach (array_slice($_POST['MaHang'], 0, 200, true) as $i => $id) {
        $q = is_array($_POST['SoLuong'] ?? null) ? ($_POST['SoLuong'][$i] ?? '') : '';
        $price = is_array($_POST['DonGia'] ?? null) ? ($_POST['DonGia'][$i] ?? '') : '';
        if (is_string($id)) $dcPost[] = ['MaHang'=>$id, 'SoLuong'=>is_string($q) ? $q : '', 'DonGia'=>is_string($price) ? $price : ''];
    }
}
$warehouses = []; $raw = []; $recent = []; $preview = '';
try {
    $warehouses = $c->layKho(); $raw = $c->layHangVaTon(); $recent = $c->layPhieuGanDay(); $preview = $c->taoMaPreview();
} catch (Throwable $e) {
    error_log('DieuChuyen catalog: '.$e->getMessage());
    $error = $error ?: 'Không tải được danh mục kho/hàng. Vui lòng kiểm tra kết nối và thử lại.';
}
$token = is_string($_POST['dc_token'] ?? null) ? $_POST['dc_token'] : '';
if (!isset($_SESSION['dc_drafts'][$token])) {
    $token = bin2hex(random_bytes(32));
    $_SESSION['dc_drafts'] = array_slice($_SESSION['dc_drafts'] ?? [], -19, null, true);
    $_SESSION['dc_drafts'][$token] = true;
}
$products = []; $stocks = [];
foreach ($raw as $r) {
    $products[$r['MaHang']] = ['MaHang'=>$r['MaHang'], 'TenHang'=>$r['TenHang'], 'DonViTinh'=>$r['DonViTinh'], 'GiaNhapMacDinh'=>$r['GiaNhapMacDinh']];
    if ($r['MaKho']) $stocks[$r['MaKho']][$r['MaHang']] = (float)$r['SoLuongTon'];
}
$products = array_values($products);
$source = $posted ? $values['MaKhoXuat'] : ($warehouses[0]['MaKho'] ?? '');
$target = $posted ? $values['MaKhoNhap'] : ($warehouses[1]['MaKho'] ?? '');
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<?php if($error):?><div class="alert alert-error"><?=e($error)?></div><?php endif;?>

<form method="post" id="frmTransfer"><input type="hidden" name="uc_action" value="save_transfer"><input type="hidden" name="dc_token" value="<?= e($token) ?>">
<div class="card"><div class="section-head"><div><h2>1. Thông tin chung phiếu điều chuyển</h2><p>Mã phiếu và ngày điều chuyển được hệ thống quản lý.</p></div></div>
 <div class="form-grid">
  <div class="field"><label>Mã phiếu điều chuyển</label><input class="code-input" name="MaPhieuDC" value="<?=e($values['MaPhieuDC'] ?: $preview)?>" readonly></div>
  <div class="field"><label>Ngày điều chuyển</label><input value="<?=e((new DateTimeImmutable('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d'))?>" readonly></div>
  <div class="field"><label>Kho xuất *</label><select name="MaKhoXuat" id="dcSource" required><?php foreach($warehouses as $k):?><option value="<?=e($k['MaKho'])?>" <?=$source===$k['MaKho']?'selected':''?>><?=e($k['MaKho'].' - '.$k['TenKho'])?></option><?php endforeach;?></select></div>
  <div class="field"><label>Kho nhập *</label><select name="MaKhoNhap" id="dcTarget" required><?php foreach($warehouses as $i=>$k):?><option value="<?=e($k['MaKho'])?>" <?=$target===$k['MaKho']?'selected':''?>><?=e($k['MaKho'].' - '.$k['TenKho'])?></option><?php endforeach;?></select></div>
  <div class="field" style="grid-column:1/-1"><label>Ghi chú</label><textarea maxlength="500" name="GhiChu" placeholder="Ghi chú điều chuyển (nếu có)"><?=e($values['GhiChu'])?></textarea></div>
 </div>
</div>
<div class="card"><div class="section-head"><div><h2>2. Chi tiết hàng hóa xuất chuyển</h2><p>Số lượng phải là số nguyên dương và không vượt tồn kho.</p></div><button type="button" class="btn btn-primary" id="dcAdd">+ Thêm dòng</button></div>
 <div class="table-wrap"><table><thead><tr><th>STT</th><th>Mã hàng</th><th>ĐVT</th><th>Tồn kho xuất</th><th>Số lượng chuyển</th><th>Đơn giá</th><th class="text-right">Thành tiền</th><th></th></tr></thead><tbody id="dcBody"></tbody></table></div>
 <div class="finance-box"><div class="finance-total"><span>TỔNG GIÁ TRỊ PHIẾU CHUYỂN</span><span id="dcTotal">0 ₫</span></div></div>
 <div class="actions"><button type="button" class="btn btn-light" onclick="if(confirmAction('Hủy thao tác lập phiếu?')) location.href='index.php?page=dieuchuyen'">Hủy</button><button class="btn btn-primary">Xác nhận lưu phiếu</button></div>
</div></form>
<div class="card"><div class="section-head"><div><h2>Phiếu điều chuyển gần đây</h2></div></div><div class="table-wrap"><table><thead><tr><th>Mã phiếu</th><th>Ngày</th><th>Kho xuất</th><th>Kho nhập</th><th class="text-right">Tổng giá trị</th><th>Trạng thái</th></tr></thead><tbody>
<?php if(!$recent):?><tr><td colspan="6" class="empty-state">Chưa có phiếu điều chuyển.</td></tr><?php endif;?>
<?php foreach($recent as $r):?><tr><td class="text-blue"><?=e($r['MaPhieuDC'])?></td><td><?=e($r['NgayDieuChuyen'])?></td><td><?=e($r['KhoXuat'])?></td><td><?=e($r['KhoNhap'])?></td><td class="text-right"><b><?=money($r['TongGiaTri'])?></b></td><td><span class="badge badge-green"><?=e($r['TrangThai'])?></span></td></tr><?php endforeach;?>
</tbody></table></div></div>
<script>
const DC_PRODUCTS=<?=json_encode($products,$jsonFlags)?>;
const DC_STOCKS=<?=json_encode($stocks,$jsonFlags)?>;
const DC_POST=<?=json_encode($dcPost,$jsonFlags)?>;
let dcRows=DC_POST.map(x=>({...x}));
const dcBody=document.getElementById('dcBody');
function dcStock(ma){return Number(DC_STOCKS[document.getElementById('dcSource').value]?.[ma]||0)}
function firstStockProduct(){return DC_PRODUCTS.find(p=>dcStock(p.MaHang)>0&&!dcRows.some(r=>r.MaHang===p.MaHang))}
function dcAmount(r){const q=Number(r.SoLuong),p=Number(r.DonGia);return Number.isFinite(q)&&Number.isFinite(p)?q*Math.round(p*100):0}
function dcTotals(){
 dcRows.forEach((r,i)=>{const cell=dcBody.children[i]?.querySelector('[data-amount]');if(cell)cell.textContent=formatMoney(dcAmount(r)/100)});
 document.getElementById('dcTotal').textContent=formatMoney(dcRows.reduce((sum,r)=>sum+dcAmount(r),0)/100);
}
function renderDc(){
 dcBody.innerHTML='';
 if(!dcRows.length)dcBody.innerHTML='<tr><td colspan="8" class="empty-state">Chưa có mặt hàng. Nhấn Thêm dòng để chọn hàng có tồn tại kho xuất.</td></tr>';
 dcRows.forEach((r,i)=>{
  const available=DC_PRODUCTS.filter(p=>dcStock(p.MaHang)>0||p.MaHang===r.MaHang);
  let opts=available.map(p=>`<option value="${escapeHtml(p.MaHang)}" ${p.MaHang===r.MaHang?'selected':''}>${escapeHtml(p.MaHang+' - '+p.TenHang)}</option>`).join('');
  if(!available.some(p=>p.MaHang===r.MaHang))opts=`<option value="${escapeHtml(r.MaHang)}" selected>${escapeHtml(r.MaHang)} (không còn khả dụng)</option>`+opts;
  const product=DC_PRODUCTS.find(p=>p.MaHang===r.MaHang),stock=dcStock(r.MaHang);
  dcBody.insertAdjacentHTML('beforeend',`<tr><td class="text-center">${i+1}</td><td><select class="table-input select-wide" name="MaHang[]" onchange="dcField(${i},'MaHang',this.value,true)">${opts}</select></td><td>${escapeHtml(product?.DonViTinh||'')}</td><td class="text-center"><span class="badge ${stock>0?'badge-blue':'badge-red'}">${stock}</span></td><td><input required class="table-input" type="number" min="1" step="1" max="${Math.min(stock,999999999)}" name="SoLuong[]" value="${escapeHtml(r.SoLuong)}" oninput="dcField(${i},'SoLuong',this.value)"></td><td><input required class="table-input" type="number" min="0" max="999999999999.99" step="0.01" name="DonGia[]" value="${escapeHtml(r.DonGia)}" oninput="dcField(${i},'DonGia',this.value)"></td><td class="text-right text-blue" data-amount></td><td><button type="button" class="btn btn-danger btn-sm" onclick="dcDelete(${i})">Xóa</button></td></tr>`);
 });
 dcTotals();
}
function dcField(i,f,v,productChanged=false){
 dcRows[i][f]=v;
 if(productChanged){const p=DC_PRODUCTS.find(x=>x.MaHang===v);if(p){dcRows[i].DonGia=p.GiaNhapMacDinh;dcRows[i].SoLuong=1}renderDc()}
 else dcTotals(); // Không dựng lại input khi gõ: giữ focus và vị trí con trỏ.
}
function dcDelete(i){dcRows.splice(i,1);renderDc()}
function addDc(){
 if(dcRows.length>=200){alert('Tối đa 200 dòng hàng.');return}
 const p=firstStockProduct();if(!p){alert('Không còn mặt hàng có tồn chưa được thêm tại kho xuất.');return}
 dcRows.push({MaHang:p.MaHang,SoLuong:1,DonGia:p.GiaNhapMacDinh});renderDc();
}
document.getElementById('dcAdd').addEventListener('click',addDc);
let dcPreviousSource=document.getElementById('dcSource').value;
document.getElementById('dcSource').addEventListener('change',event=>{
 if(dcRows.length&&!confirmAction('Đổi kho xuất sẽ xóa các dòng hàng đang nhập. Tiếp tục?')){event.target.value=dcPreviousSource;return}
 dcPreviousSource=event.target.value;dcRows=[];renderDc();
});
document.getElementById('frmTransfer').addEventListener('submit',event=>{
 let error='';const seen=new Set();
 if(!dcRows.length)error='Vui lòng thêm ít nhất một mặt hàng.';
 if(document.getElementById('dcSource').value===document.getElementById('dcTarget').value)error='Kho xuất và kho nhập không được trùng nhau.';
 for(const r of dcRows){
  if(seen.has(r.MaHang))error='Mặt hàng bị thêm trùng.';seen.add(r.MaHang);
  if(!/^[0-9]{1,9}$/.test(String(r.SoLuong))||Number(r.SoLuong)<=0)error='Số lượng phải là số nguyên dương, tối đa 9 chữ số.';
  if(Number(r.SoLuong)>dcStock(r.MaHang))error='Số lượng xuất vượt quá tồn kho hiện tại: '+r.MaHang;
  if(!/^[0-9]{1,12}(\.[0-9]{1,2})?$/.test(String(r.DonGia)))error='Đơn giá không hợp lệ (tối đa 2 số lẻ).';
 }
 if(dcRows.reduce((sum,r)=>sum+dcAmount(r),0)>9000000000000000)error='Tổng giá trị phiếu vượt giới hạn cho phép.';
 if(error){event.preventDefault();alert(error);return}
 event.currentTarget.querySelector('button:not([type="button"])').disabled=true;
});
renderDc();
</script>
