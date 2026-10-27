<?php
declare(strict_types=1);

namespace StockFlow\Core;

use StockFlow\Domain\DomainError;
use StockFlow\Domain\Forbidden;
use StockFlow\Domain\NotFound;
use StockFlow\Domain\User;
use StockFlow\Http\Controller as C;
use StockFlow\Service\AuthService;
use StockFlow\Service\ChangeTracker;
use StockFlow\Service\NavBadges;
use StockFlow\Service\SettingsService;
use Throwable;

/** Front controller: định tuyến, xác thực, CSRF, xử lý lỗi, header bảo mật. */
final class App
{
    private Container $c;
    private Router $router;

    public function __construct(?Container $container = null)
    {
        $this->c = $container ?? new Container();
        if (!$this->c->has(Db::class)) {
            $this->c->set(Db::class, Db::connect(Config::database()));
        }
        $this->router = $this->routes();
    }

    private function routes(): Router
    {
        $r = new Router();
        $r->get('/login', [C\AuthController::class, 'form'], true);
        $r->post('/login', [C\AuthController::class, 'login'], true);
        $r->post('/logout', [C\AuthController::class, 'logout']);

        $r->get('/', [C\DashboardController::class, 'index']);
        $r->get('/api/badges', [C\DashboardController::class, 'badges']);
        $r->post('/api/notifications/seen', [C\DashboardController::class, 'notificationsSeen']);
        $r->get('/inventory', [C\InventoryController::class, 'index']);
        $r->post('/items/price', [C\InventoryController::class, 'price']);
        $r->post('/items/cost', [C\InventoryController::class, 'cost']);
        $r->get('/scan/lookup', [C\ScanController::class, 'lookup']);
        $r->get('/proposals', [C\ProposalController::class, 'index']);
        $r->post('/proposals', [C\ProposalController::class, 'store']);
        $r->post('/proposals/{id}/approve', [C\ProposalController::class, 'approve']);
        $r->post('/proposals/{id}/reject', [C\ProposalController::class, 'reject']);
        $r->post('/proposals/{id}/cancel', [C\ProposalController::class, 'cancel']);
        $r->post('/items/barcode', [C\ScanController::class, 'setBarcode']);
        $r->get('/settings', [C\SettingsController::class, 'index']);
        $r->post('/settings/profile', [C\SettingsController::class, 'profile']);
        $r->post('/settings/password', [C\SettingsController::class, 'password']);
        $r->post('/settings/preferences', [C\SettingsController::class, 'preferences']);
        $r->post('/settings/avatar', [C\SettingsController::class, 'avatarUpload']);
        $r->post('/settings/avatar/remove', [C\SettingsController::class, 'avatarRemove']);
        $r->get('/avatar/{id}', [C\SettingsController::class, 'avatarFile']);
        $r->post('/settings/system', [C\SettingsController::class, 'system']);
        $r->get('/finova/orders', [C\FinovaController::class, 'orders']);

        $r->get('/vouchers', [C\VoucherController::class, 'index']);
        $r->get('/vouchers/new', [C\VoucherController::class, 'create']);
        $r->post('/vouchers', [C\VoucherController::class, 'store']);
        $r->get('/vouchers/{id}', [C\VoucherController::class, 'show']);
        $r->get('/vouchers/{id}/print', [C\VoucherController::class, 'print']);
        $r->post('/vouchers/{id}/confirm', [C\VoucherController::class, 'confirm']);
        $r->post('/vouchers/{id}/cancel', [C\VoucherController::class, 'cancel']);
        $r->post('/vouchers/{id}/recall', [C\VoucherController::class, 'recall']);
        $r->post('/vouchers/{id}/not-received', [C\VoucherController::class, 'notReceived']);
        $r->post('/vouchers/{id}/correct', [C\VoucherController::class, 'correct']);
        $r->get('/transfers/new', [C\VoucherController::class, 'createTransfer']);
        $r->post('/transfers', [C\VoucherController::class, 'storeTransfer']);

        $r->get('/requests', [C\TransferRequestController::class, 'index']);
        $r->get('/requests/new', [C\TransferRequestController::class, 'create']);
        $r->post('/requests', [C\TransferRequestController::class, 'store']);
        $r->post('/requests/{id}/approve', [C\TransferRequestController::class, 'approve']);
        $r->post('/requests/{id}/accept', [C\TransferRequestController::class, 'accept']);
        $r->post('/requests/{id}/reject', [C\TransferRequestController::class, 'reject']);
        $r->post('/requests/{id}/cancel', [C\TransferRequestController::class, 'cancel']);

        $r->get('/counts', [C\CountController::class, 'index']);
        $r->post('/counts', [C\CountController::class, 'store']);
        $r->get('/counts/{id}', [C\CountController::class, 'show']);
        $r->post('/counts/{id}/save', [C\CountController::class, 'save']);
        $r->post('/counts/{id}/submit', [C\CountController::class, 'submit']);
        $r->post('/counts/{id}/approve', [C\CountController::class, 'approve']);
        $r->post('/counts/{id}/reject', [C\CountController::class, 'reject']);
        $r->post('/counts/{id}/cancel', [C\CountController::class, 'cancel']);

        $r->get('/history', [C\HistoryController::class, 'index']);
        $r->get('/reconcile', [C\HistoryController::class, 'reconcile']);
        $r->get('/reports', [C\HistoryController::class, 'reports']);
        $r->get('/valuation', [C\ValuationController::class, 'index']);
        $r->get('/reports/stock.csv', [C\HistoryController::class, 'exportStock']);
        $r->get('/reports/movements.csv', [C\HistoryController::class, 'exportMovements']);
        $r->get('/reports/reconcile.csv', [C\HistoryController::class, 'exportReconcile']);

        $r->get('/warehouses', [C\AdminController::class, 'warehouses']);
        $r->post('/warehouses', [C\AdminController::class, 'addWarehouse']);
        $r->post('/warehouses/address', [C\AdminController::class, 'updateWarehouseAddress']);
        $r->post('/warehouses/staff', [C\AdminController::class, 'addStaff']);
        $r->post('/warehouses/staff/update', [C\SettingsController::class, 'staffUpdate']);
        $r->post('/warehouses/staff/active', [C\SettingsController::class, 'staffActive']);
        $r->get('/partners', [C\PartnerController::class, 'index']);
        $r->post('/partners', [C\PartnerController::class, 'add']);
        $r->post('/partners/update', [C\PartnerController::class, 'update']);
        $r->post('/partners/active', [C\PartnerController::class, 'active']);
        $r->get('/items', [C\AdminController::class, 'items']);
        $r->post('/items', [C\AdminController::class, 'addItem']);
        $r->post('/items/update', [C\AdminController::class, 'updateItem']);
        $r->post('/categories', [C\AdminController::class, 'addCategory']);
        $r->post('/units', [C\AdminController::class, 'addUnit']);
        $r->post('/batches', [C\AdminController::class, 'addBatch']);
        $r->get('/users', [C\AdminController::class, 'users']);
        $r->post('/users', [C\AdminController::class, 'addUser']);
        $r->post('/users/update', [C\AdminController::class, 'updateUser']);
        $r->post('/users/active', [C\AdminController::class, 'setUserActive']);
        $r->post('/users/password', [C\AdminController::class, 'resetUserPassword']);
        $r->get('/fleet', [C\FleetController::class, 'index']);
        $r->post('/fleet/trips/reply', [C\FleetController::class, 'reply']);
        $r->post('/fleet/trips/loss', [C\FleetController::class, 'confirmLoss']);
        $r->post('/fleet/losses/adjust', [C\FleetController::class, 'adjustLoss']);
        $r->post('/fleet/drivers/pay', [C\FleetController::class, 'driverPay']);
        $r->post('/fleet/vehicles', [C\FleetController::class, 'addVehicle']);
        $r->post('/fleet/vehicles/update', [C\FleetController::class, 'updateVehicle']);
        $r->post('/fleet/vehicles/delete', [C\FleetController::class, 'deleteVehicle']);
        $r->post('/fleet/vehicles/active', [C\FleetController::class, 'vehicleActive']);
        $r->post('/fleet/vehicles/move', [C\FleetController::class, 'vehicleMove']);
        $r->post('/fleet/drivers/update', [C\FleetController::class, 'updateDriver']);
        $r->post('/fleet/drivers/delete', [C\FleetController::class, 'deleteDriver']);
        $r->post('/fleet/drivers/active', [C\FleetController::class, 'driverActive']);
        $r->get('/roles', [C\AdminController::class, 'roles']);
        $r->post('/roles', [C\AdminController::class, 'saveRoles']);
        $r->post('/roles/reset', [C\AdminController::class, 'resetRoles']);
        return $r;
    }

    public function handle(Request $req): Response
    {
        $session = new Session();
        $session->start();
        $this->c->set(Session::class, $session);
        return $this->secure($this->dispatch($req, $session));
    }

    private function dispatch(Request $req, Session $session): Response
    {
        $view = $this->c->get(View::class);
        try {
            $match = $this->router->match($req->method, $req->path);
            if ($match === null) {
                return $this->router->knowsPath($req->path)
                    ? $view->render('errors/error', ['code' => 405, 'message' => 'Phương thức không được hỗ trợ'], 'layout/bare', 405)
                    : throw new NotFound('Không tìm thấy trang');
            }
            $user = null;
            if ($uid = $session->userId()) {
                $user = $this->c->get(AuthService::class)->find($uid);
                if (!$user) {
                    $session->logout();
                }
            }
            if ($user) {
                $settings = $this->c->get(SettingsService::class);
                $idle = $settings->system()['idle_minutes'];
                if ($idle > 0 && $session->idleSeconds() > $idle * 60) {
                    $session->logout();
                    $session->flash('err', 'Phiên làm việc đã hết do không thao tác quá ' . $idle . ' phút. Hãy đăng nhập lại.');
                    $user = null;
                } else {
                    $session->touch();
                    $prefs = $settings->preferences($user);
                    Paginator::$default = $prefs['rows'];
                    $session->setPrefs($prefs);
                }
            }
            if ($user) {   // số nhỏ trên menu: số chuyến chưa thấy hàng ở «Đội xe & tài xế», số phiếu chờ xác nhận ở «Phiếu nhập xuất»
                $view->setBadges(fn() => $this->c->get(NavBadges::class)->for($user));
                $view->setNotifications(fn() => $this->c->get(\StockFlow\Service\NotificationService::class)->all($user));
                $tracker = $this->c->get(ChangeTracker::class);
                if ($req->method === 'GET' && ($section = ChangeTracker::PATHS[$req->path] ?? null)) {   // mở trang danh sách: bôi đậm cái mới rồi dời mốc «đã xem»
                    $ref = parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH);
                    $view->setChanges($tracker->visit($user, $section, is_string($ref) && '/' . trim($ref, '/') === $req->path));   // cùng trang (lọc, phân trang) ⇒ giữ phần đã bôi đậm
                }
                $view->setNewCounts(fn() => $tracker->counts($user));
            }
            if (!$match['public'] && !$user) {
                return Response::redirect('/login');
            }
            if ($req->method === 'POST' && !$session->checkCsrf($req->str('csrf'))) {
                return $view->render('errors/error', ['code' => 403, 'message' => 'Phiên làm việc hết hạn hoặc yêu cầu không hợp lệ (CSRF). Hãy tải lại trang và thử lại.'], 'layout/bare', 403);
            }
            [$class, $method] = $match['handler'];
            return $this->c->get($class)->$method($req, $match['params'], $user);
        } catch (Forbidden $e) {
            return $view->render('errors/error', ['code' => 403, 'message' => $e->getMessage()], 'layout/bare', 403);
        } catch (NotFound $e) {
            return $view->render('errors/error', ['code' => 404, 'message' => $e->getMessage()], 'layout/bare', 404);
        } catch (DomainError $e) {
            return $view->render('errors/error', ['code' => 422, 'message' => $e->getMessage()], 'layout/bare', 422);
        } catch (Throwable $e) {
            error_log((string)$e);
            $dev = getenv('APP_DEBUG') === '1';
            return $view->render('errors/error', ['code' => 500, 'message' => $dev ? $e->getMessage() : 'Có lỗi hệ thống. Yêu cầu chưa được xử lý, dữ liệu không bị thay đổi dở dang.'], 'layout/bare', 500);
        }
    }

    private function secure(Response $r): Response
    {
        return new Response($r->body, $r->status, $r->headers + [
            'Content-Security-Policy' => "default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Cache-Control' => 'no-store',
        ]);
    }
}
