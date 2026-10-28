<?php
if (!defined('APP_ENTRY')) { http_response_code(403); exit; }
include_once __DIR__ . '/../Model/mDieuChuyen.php';

class clsCDieuChuyen {
    function layKho() { return (new clsMDieuChuyen())->getKho(); }
    function layHangVaTon() { return (new clsMDieuChuyen())->getHangVaTon(); }
    function layPhieuGanDay() { return (new clsMDieuChuyen())->getPhieuGanDay(); }
    function taoMaPreview() { return (new clsMDieuChuyen())->nextCode(); }

    function luuPhieu($data, $maThuKho) {
        try {
            if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role_code'] ?? '', ['ADMIN','THUKHO'], true)
                || (int)$_SESSION['user_id'] !== (int)$maThuKho) {
                throw new InvalidArgumentException('Bạn không có quyền lập phiếu điều chuyển.');
            }
            $token = $data['dc_token'] ?? null;
            if (!is_string($token) || !isset($_SESSION['dc_drafts'][$token])) {
                throw new InvalidArgumentException('Phiên lập phiếu không hợp lệ hoặc phiếu đã được lưu. Vui lòng mở phiếu mới.');
            }
            foreach (['MaKhoXuat','MaKhoNhap','GhiChu'] as $key) {
                if (isset($data[$key]) && !is_string($data[$key])) throw new InvalidArgumentException('Dữ liệu phiếu không hợp lệ.');
            }
            $khoXuat = trim($data['MaKhoXuat'] ?? '');
            $khoNhap = trim($data['MaKhoNhap'] ?? '');
            $note = trim($data['GhiChu'] ?? '');
            if ($khoXuat === '' || $khoNhap === '') throw new InvalidArgumentException('Vui lòng chọn kho xuất và kho nhập.');
            if ($khoXuat === $khoNhap) throw new InvalidArgumentException('Kho xuất và kho nhập không được trùng nhau.');
            if (mb_strlen($note, 'UTF-8') > 500) throw new InvalidArgumentException('Ghi chú không được vượt quá 500 ký tự.');
            $maHang = $data['MaHang'] ?? [];
            $soLuong = $data['SoLuong'] ?? [];
            $donGia = $data['DonGia'] ?? [];
            if (!is_array($maHang) || !is_array($soLuong) || !is_array($donGia)
                || array_keys($maHang) !== array_keys($soLuong) || array_keys($maHang) !== array_keys($donGia)) {
                throw new InvalidArgumentException('Chi tiết hàng hóa không hợp lệ.');
            }
            if (!$maHang || count($maHang) > 200) throw new InvalidArgumentException('Phiếu phải có từ 1 đến 200 dòng hàng.');
            $rows = [];
            foreach ($maHang as $i => $mh) {
                if (!is_string($mh) || trim($mh) === '' || !is_string($soLuong[$i]) || !is_string($donGia[$i])) {
                    throw new InvalidArgumentException('Vui lòng nhập đầy đủ mặt hàng, số lượng và đơn giá.');
                }
                // Giữ chuỗi gốc để Model kiểm tra trước khi chuyển sang số.
                $rows[] = ['MaHang'=>trim($mh), 'SoLuong'=>trim($soLuong[$i]), 'DonGia'=>trim($donGia[$i])];
            }
            $result = (new clsMDieuChuyen())->savePhieu([
                'MaKhoXuat'=>$khoXuat, 'MaKhoNhap'=>$khoNhap,
                'MaThuKho'=>(int)$maThuKho, 'GhiChu'=>$note,
            ], $rows);
            if ($result['ok']) unset($_SESSION['dc_drafts'][$token]);
            return $result;
        } catch (InvalidArgumentException $e) {
            return ['ok'=>false, 'message'=>$e->getMessage()];
        }
    }
}
