<?php
if (!defined('APP_ENTRY')) { http_response_code(403); exit; }
include_once __DIR__ . '/mKetnoi.php';

class clsMDieuChuyen {
    private function nextCodeWithConn($conn){$rs=$conn->query("SELECT COALESCE(MAX(CAST(SUBSTRING(MaPhieuDC,3) AS UNSIGNED)),0)+1 n FROM PhieuDieuChuyen WHERE MaPhieuDC REGEXP '^DC[0-9]+$'");return 'DC'.str_pad((string)((int)$rs->fetch_assoc()['n']),6,'0',STR_PAD_LEFT);}
    function nextCode(){$p=new clsKetnoi();$c=$p->moketnoi();$x=$this->nextCodeWithConn($c);$p->dongketnoi($c);return $x;}
    function getKho(){$p=new clsKetnoi();$c=$p->moketnoi();$r=$c->query("SELECT * FROM Kho WHERE TrangThai='Hoạt động' ORDER BY TenKho")->fetch_all(MYSQLI_ASSOC);$p->dongketnoi($c);return $r;}
    function getHangVaTon(){
        $p=new clsKetnoi();$c=$p->moketnoi();
        $r=$c->query("SELECT hh.MaHang,hh.TenHang,hh.GiaNhapMacDinh,hh.DonViTinh,tk.MaKho,tk.SoLuongTon FROM HangHoa hh LEFT JOIN TonKho tk ON tk.MaHang=hh.MaHang WHERE hh.TrangThai='Hoạt động' ORDER BY hh.MaHang,tk.MaKho")->fetch_all(MYSQLI_ASSOC);
        $p->dongketnoi($c);return $r;
    }
    function getPhieuGanDay(){
        $p=new clsKetnoi();$c=$p->moketnoi();
        $r=$c->query("SELECT p.MaPhieuDC,p.NgayDieuChuyen,kx.TenKho KhoXuat,kn.TenKho KhoNhap,p.TongGiaTri,p.TrangThai
                      FROM PhieuDieuChuyen p JOIN Kho kx ON kx.MaKho=p.MaKhoXuat JOIN Kho kn ON kn.MaKho=p.MaKhoNhap
                      ORDER BY p.NgayDieuChuyen DESC,p.MaPhieuDC DESC LIMIT 12")->fetch_all(MYSQLI_ASSOC);
        $p->dongketnoi($c);return $r;
    }
    private function decimalMoney($cents) {
        return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    function savePhieu($h, $rows) {
        $p = new clsKetnoi(); $conn = null; $locked = false;
        try {
            if (!$rows) throw new InvalidArgumentException('Phiếu chuyển phải có ít nhất một mặt hàng.');
            if ($h['MaKhoXuat'] === $h['MaKhoNhap']) throw new InvalidArgumentException('Kho xuất và kho nhập không được trùng nhau.');
            $seen = []; $prepared = []; $totalCents = 0;
            foreach ($rows as $r) {
                if (!preg_match('/^[0-9]{1,9}$/D', (string)$r['SoLuong']) || (int)$r['SoLuong'] <= 0) {
                    throw new InvalidArgumentException('Số lượng chuyển phải là số nguyên dương, tối đa 9 chữ số.');
                }
                if (!preg_match('/^[0-9]{1,12}(\.[0-9]{1,2})?$/D', (string)$r['DonGia'])) {
                    throw new InvalidArgumentException('Đơn giá phải không âm, tối đa 12 chữ số phần nguyên và 2 chữ số thập phân.');
                }
                if (isset($seen[$r['MaHang']])) throw new InvalidArgumentException('Mặt hàng '.$r['MaHang'].' bị thêm trùng.');
                $seen[$r['MaHang']] = true;
                $parts = explode('.', (string)$r['DonGia']);
                $priceCents = (int)$parts[0] * 100 + (int)str_pad($parts[1] ?? '', 2, '0');
                $quantity = (int)$r['SoLuong'];
                // Tổng tiền trong giới hạn số nguyên chính xác của trình duyệt và DECIMAL(18,2).
                if ($priceCents > 0 && $quantity > intdiv(9000000000000000 - $totalCents, $priceCents)) {
                    throw new InvalidArgumentException('Tổng giá trị phiếu vượt giới hạn cho phép.');
                }
                $lineCents = $quantity * $priceCents; $totalCents += $lineCents;
                $prepared[] = ['MaHang'=>$r['MaHang'], 'SoLuong'=>$quantity,
                    'DonGia'=>$this->decimalMoney($priceCents), 'ThanhTien'=>$this->decimalMoney($lineCents)];
            }
            usort($prepared, fn($a, $b) => strcmp($a['MaHang'], $b['MaHang']));
            $conn = $p->moketnoi();
            // Serialize code generation across connections; MAX+1 alone can produce duplicate codes.
            $locked = (int)$conn->query("SELECT GET_LOCK('qlbh7_dieuchuyen_code', 5)")->fetch_row()[0] === 1;
            if (!$locked) throw new RuntimeException('Không lấy được khóa mã phiếu.');
            $conn->begin_transaction();
            $stmt = $conn->prepare("SELECT MaKho FROM Kho WHERE MaKho IN (?,?) AND TrangThai='Hoạt động' LOCK IN SHARE MODE");
            $stmt->bind_param('ss', $h['MaKhoXuat'], $h['MaKhoNhap']); $stmt->execute();
            if ($stmt->get_result()->num_rows !== 2) throw new InvalidArgumentException('Kho xuất hoặc kho nhập không còn hợp lệ.');
            $stmt->close();
            $stockStmt = $conn->prepare("SELECT tk.SoLuongTon FROM TonKho tk JOIN HangHoa hh ON hh.MaHang=tk.MaHang WHERE tk.MaKho=? AND tk.MaHang=? AND hh.TrangThai='Hoạt động' FOR UPDATE");
            foreach ($prepared as $r) {
                $stockStmt->bind_param('ss', $h['MaKhoXuat'], $r['MaHang']); $stockStmt->execute();
                $stock = $stockStmt->get_result()->fetch_assoc();
                if (!$stock || (float)$stock['SoLuongTon'] < $r['SoLuong']) {
                    throw new InvalidArgumentException('Số lượng xuất vượt quá tồn kho thực tế hiện tại: '.$r['MaHang']);
                }
            }
            $stockStmt->close();
            // Generate on server inside the lock; submitted preview code is never trusted.
            $code = $this->nextCodeWithConn($conn);
            if (strlen($code) > 20) throw new RuntimeException('Mã phiếu vượt độ dài cột.');
            $ngay = (new DateTimeImmutable('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d');
            $status = 'Đã duyệt'; $total = $this->decimalMoney($totalCents);
            $stmt = $conn->prepare("INSERT INTO PhieuDieuChuyen(MaPhieuDC,MaKhoXuat,MaKhoNhap,MaThuKho,NgayDieuChuyen,GhiChu,TongGiaTri,TrangThai) VALUES(?,?,?,?,?,?,?,?)");
            $stmt->bind_param('sssissss', $code, $h['MaKhoXuat'], $h['MaKhoNhap'], $h['MaThuKho'], $ngay, $h['GhiChu'], $total, $status);
            $stmt->execute(); $stmt->close();
            $ins = $conn->prepare("INSERT INTO ChiTietDieuChuyen(MaPhieuDC,MaHang,SoLuong,DonGia,ThanhTien) VALUES(?,?,?,?,?)");
            $upd = $conn->prepare("UPDATE TonKho SET SoLuongTon=SoLuongTon-?,CapNhatLuc=CURRENT_TIMESTAMP WHERE MaKho=? AND MaHang=? AND SoLuongTon>=?");
            foreach ($prepared as $r) {
                $ins->bind_param('ssiss', $code, $r['MaHang'], $r['SoLuong'], $r['DonGia'], $r['ThanhTien']); $ins->execute();
                $upd->bind_param('issi', $r['SoLuong'], $h['MaKhoXuat'], $r['MaHang'], $r['SoLuong']); $upd->execute();
                if ($upd->affected_rows !== 1) throw new RuntimeException('Tồn kho đã thay đổi.');
            }
            $ins->close(); $upd->close(); $conn->commit();
            return ['ok'=>true, 'message'=>'Lập phiếu chuyển kho thành công: '.$code, 'code'=>$code];
        } catch (Throwable $e) {
            if ($conn) { try { $conn->rollback(); } catch (Throwable $ignore) {} }
            if ($e instanceof InvalidArgumentException) return ['ok'=>false, 'message'=>$e->getMessage()];
            error_log('DieuChuyen: '.$e->getMessage());
            return ['ok'=>false, 'message'=>'Không thể ghi dữ liệu do lỗi kết nối CSDL hoặc tồn kho vừa bị thay đổi bởi phiên khác. Vui lòng thử lại.'];
        } finally {
            if ($conn) {
                if ($locked) { try { $conn->query("SELECT RELEASE_LOCK('qlbh7_dieuchuyen_code')"); } catch (Throwable $ignore) {} }
                $p->dongketnoi($conn);
            }
        }
    }
}
