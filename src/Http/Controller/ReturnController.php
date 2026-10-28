<?php
declare(strict_types=1);

namespace StockFlow\Http\Controller;

use StockFlow\Core\Paginator;
use StockFlow\Core\Request;
use StockFlow\Core\Response;
use StockFlow\Core\Session;
use StockFlow\Core\View;
use StockFlow\Domain\Permission;
use StockFlow\Domain\User;
use StockFlow\Service\Authorizer;
use StockFlow\Service\SupplierReturnService;

/** Phiếu trả hàng không đạt chất lượng cho nhà cung cấp và theo dõi hàng cấp lại. */
final class ReturnController extends Controller
{
    public function __construct(View $view, Session $session, Authorizer $auth, private SupplierReturnService $returns, private \StockFlow\Core\Db $db)
    {
        parent::__construct($view, $session, $auth);
    }

    public function index(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemPhieu);
        $scope = $this->auth->visibleWarehouseIds($user);
        $status = isset(SupplierReturnService::STATUS[$r->str('status')]) ? $r->str('status') : 'CHO_CAP_LAI';
        [$rows, $pg] = Paginator::of($this->returns->list($scope, $status), $r);
        return $this->render('returns/index', $user, 'returns', [
            'rows' => $rows, 'pg' => $pg, 'status' => $status, 'counts' => $this->returns->counts($scope),
        ], 'Trả hàng nhà cung cấp');
    }

    public function show(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemPhieu);
        $t = $this->returns->find((int)$p['id']);
        $this->auth->requireVisible($user, (int)$t['kho_id']);
        return $this->render('returns/show', $user, 'returns', [
            't' => $t,
            'canReceive' => $this->auth->can($user, (int)$t['kho_id'], Permission::LapPhieuNhap),
            'canClose' => $this->auth->can($user, (int)$t['kho_id'], Permission::XacNhanPhieu),
        ], $t['ma']);
    }

    /** Phiếu in giao cho nhà cung cấp cùng lô hàng trả lại. */
    public function print(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemPhieu);
        $t = $this->returns->find((int)$p['id']);
        $this->auth->requireVisible($user, (int)$t['kho_id']);
        $partner = $this->db->one("SELECT nguoi_lien_he, so_dien_thoai, dia_chi FROM doi_tac WHERE loai = 'SUPPLIER' AND ten = ?", [$t['doi_tac']]);
        return $this->view->render('returns/print', ['t' => $t, 'partner' => $partner, 'user' => $user], '');
    }

    public function close(Request $r, array $p, ?User $user): Response
    {
        $id = (int)$p['id'];
        return $this->act(fn() => $this->returns->close($user, $id), 'Đã đóng phiếu trả hàng — nhà cung cấp không cấp lại.', "/returns/$id");
    }
}
