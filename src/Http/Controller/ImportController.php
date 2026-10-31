<?php
declare(strict_types=1);

namespace StockFlow\Http\Controller;

use StockFlow\Core\Request;
use StockFlow\Core\Response;
use StockFlow\Core\Session;
use StockFlow\Core\View;
use StockFlow\Domain\DomainError;
use StockFlow\Domain\Forbidden;
use StockFlow\Domain\Permission;
use StockFlow\Domain\User;
use StockFlow\Service\Authorizer;
use StockFlow\Service\ImportService;

/** Nhập hàng loạt từ CSV: danh mục mặt hàng (quản trị) và tồn đầu kỳ (quản lý / nhân viên kho → một phiếu nhập nháp). Luôn xem trước rồi mới ghi. */
final class ImportController extends Controller
{
    public function __construct(View $view, Session $session, Authorizer $auth, private ImportService $import)
    {
        parent::__construct($view, $session, $auth);
    }

    private function canItems(?User $user): bool
    {
        return $user !== null && $this->auth->allows($user, Permission::QuanLyMatHang);
    }

    private function canStock(?User $user): bool
    {
        return $user !== null && $user->warehouseId !== null && $this->auth->allows($user, Permission::LapPhieuNhap);
    }

    /** Loại nhập đang chọn; chặn nếu tài khoản không có quyền với loại đó. */
    private function kind(Request $r, ?User $user): string
    {
        $kind = $r->str('type') === 'stock' ? 'stock' : 'items';
        if ($kind === 'items' ? !$this->canItems($user) : !$this->canStock($user)) {
            throw new Forbidden($kind === 'items' ? 'Chỉ quản trị hệ thống mới nhập danh mục mặt hàng từ file' : 'Chỉ tài khoản có chức năng lập phiếu nhập ở một kho mới nhập tồn đầu kỳ');
        }
        return $kind;
    }

    public function index(Request $r, array $p, ?User $user): Response
    {
        if (!$this->canItems($user) && !$this->canStock($user)) {
            throw new Forbidden('Tài khoản này không có chức năng nhập hàng từ file');
        }
        return $this->render('import/index', $user, 'import', ['canItems' => $this->canItems($user), 'canStock' => $this->canStock($user), 'max' => ImportService::MAX_ROWS], 'Nhập từ file');
    }

    public function template(Request $r, array $p, ?User $user): Response
    {
        $kind = $this->kind($r, $user);
        return $kind === 'items'
            ? Response::csv('mau-mat-hang.csv', ImportService::ITEM_HEADERS, [['BOX-XL', 'Thùng carton 60x40x40 cm', 'cái', 'Vật tư đóng gói', 'không', 50, 20, 0, ''], ['SPK-MINI', 'Loa bluetooth mini', 'cái', 'Điện tử', 'không', 10, 1, 12, '']])
            : Response::csv('mau-ton-dau-ky.csv', ImportService::STOCK_HEADERS, [['BOX-S', '', '', 120], ['MLK-1L', 'L2611A', '31/12/2027', 60]]);
    }

    public function preview(Request $r, array $p, ?User $user): Response
    {
        $kind = $this->kind($r, $user);
        $f = $r->files['file'] ?? null;
        try {
            $err = is_array($f) ? (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
            if ($err === UPLOAD_ERR_NO_FILE) {
                throw new DomainError('Hãy chọn một file CSV');
            }
            if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
                throw new DomainError('Tải file lên không thành công (tối đa 2 MB). Hãy thử lại.');
            }
            if ((int)$f['size'] > 2 * 1024 * 1024) {
                throw new DomainError('File quá lớn — tối đa 2 MB');
            }
            $rows = $kind === 'items' ? $this->import->previewItems((string)$f['tmp_name']) : $this->import->previewStock((string)$f['tmp_name']);
        } catch (DomainError $e) {
            $this->session->flash('err', $e->getMessage());
            return Response::redirect('/import');
        }
        $valid = array_values(array_map(fn($x) => $x['data'], array_filter($rows, fn($x) => $x['error'] === null)));
        return $this->render('import/preview', $user, 'import', ['kind' => $kind, 'rows' => $rows, 'valid' => $valid, 'fileName' => (string)($f['name'] ?? '')], 'Xem trước nhập file');
    }

    public function apply(Request $r, array $p, ?User $user): Response
    {
        $kind = $this->kind($r, $user);
        return $this->act(function () use ($r, $user, $kind) {
            $rows = json_decode($r->str('payload'), true);
            if (!is_array($rows) || $rows === [] || count($rows) > ImportService::MAX_ROWS) {
                throw new DomainError('Không có dòng hợp lệ nào để nhập');
            }
            if ($kind === 'stock') {
                $id = $this->import->applyStock($user, (int)$user->warehouseId, $rows, '');
                return '/vouchers/' . $id;
            }
            $res = $this->import->applyItems($user, $rows);
            $this->session->flash('ok', 'Đã thêm ' . $res['ok'] . ' mặt hàng' . ($res['fail'] ? ', ' . count($res['fail']) . ' dòng lỗi: ' . implode('; ', array_slice($res['fail'], 0, 3)) : '') . '.');
            return '/inventory';
        }, $kind === 'stock' ? 'Đã lập phiếu nhập nháp từ file. Kiểm tra rồi bấm Xác nhận để ghi sổ.' : '', '/import');
    }
}
