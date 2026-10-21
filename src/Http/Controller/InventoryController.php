<?php
declare(strict_types=1);

namespace StockFlow\Http\Controller;

use StockFlow\Core\Db;
use StockFlow\Core\Paginator;
use StockFlow\Core\Request;
use StockFlow\Core\Response;
use StockFlow\Core\Session;
use StockFlow\Core\View;
use StockFlow\Domain\Permission;
use StockFlow\Domain\User;
use StockFlow\Service\Authorizer;
use StockFlow\Service\StockQuery;

final class InventoryController extends Controller
{
    public function __construct(View $view, Session $session, Authorizer $auth, private StockQuery $query, private Db $db)
    {
        parent::__construct($view, $session, $auth);
    }

    public function index(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemTonKho);
        $visible = $this->auth->visibleWarehouseIds($user);
        $wh = $r->int('warehouse');
        $scope = $wh && in_array($wh, $visible, true) ? [$wh] : $visible;
        $byBatch = $r->str('view') === 'batch';
        $q = $r->str('q');
        $warehouses = $visible ? $this->db->all('SELECT id, ma, ten FROM kho WHERE id IN (' . implode(',', array_map('intval', $visible)) . ') ORDER BY ma') : [];
        [$rows, $pg] = Paginator::of($byBatch ? $this->query->onHand($scope, $q, true) : $this->query->matrix($scope, $q), $r);
        return $this->render('inventory/index', $user, 'inventory', [
            'warehouses' => $warehouses,
            'shown' => array_values(array_filter($warehouses, fn($w) => in_array((int)$w['id'], $scope, true))),
            'rows' => $rows, 'pg' => $pg,
            'byBatch' => $byBatch, 'q' => $q, 'wh' => $wh,
        ], 'Tồn kho');
    }
}
