<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Domain\Permission;
use StockFlow\Domain\User;

/**
 * Số nhỏ hiện cạnh mục menu (khoá = tên mục trong templates/layout/app.php). Dùng cho cả khung trang lẫn /api/badges (làm tươi định kỳ).
 *  - fleet: số chuyến kho đến báo «chưa nhận được hàng» (quản lý kho: chỉ xe của kho mình; admin: tất cả).
 *  - vouchers: số phiếu nháp đang chờ xác nhận / chờ nhận hàng trong phạm vi kho của tài khoản.
 */
final class NavBadges
{
    public function __construct(private Authorizer $auth, private FleetService $fleet, private VoucherRepository $vouchers, private TransferRequestService $requests, private ItemProposalService $proposals) {}

    /** @return array<string,int> chỉ gồm các mục có số > 0 */
    public function for(User $user): array
    {
        $out = [];
        if ($this->auth->allows($user, Permission::XemDoiXe)) {
            $out['fleet'] = $this->fleet->openDiscrepancies($user->isAdmin ? null : (int)$user->warehouseId);
        }
        if ($this->auth->allows($user, Permission::XemPhieu)) {
            $out['vouchers'] = (int)($this->vouchers->statusCounts($this->auth->visibleWarehouseIds($user))['DRAFT'] ?? 0);
        }
        if ($this->auth->allows($user, Permission::LapPhieuDieuChuyen) || $this->auth->allows($user, Permission::XacNhanPhieu)) {
            $out['requests'] = $this->requests->pendingFor($user);
        }
        $out['proposals'] = $this->proposals->attention($user);
        return array_filter($out);
    }
}
