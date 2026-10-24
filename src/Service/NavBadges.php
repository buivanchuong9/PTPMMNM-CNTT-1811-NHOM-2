<?php
declare(strict_types=1);

namespace StockFlow\Service;

use StockFlow\Domain\Permission;
use StockFlow\Domain\User;

/**
 * Số nhỏ hiện cạnh mục menu (khoá = tên mục trong templates/layout/app.php). Dùng cho cả khung trang lẫn /api/badges (làm tươi định kỳ).
 *  - fleet: số chuyến kho đến báo «chưa nhận được hàng» (chỉ tài khoản có quyền đội xe).
 *  - vouchers: số phiếu nháp đang chờ xác nhận / chờ nhận hàng trong phạm vi kho của tài khoản.
 */
final class NavBadges
{
    public function __construct(private Authorizer $auth, private FleetService $fleet, private VoucherRepository $vouchers) {}

    /** @return array<string,int> chỉ gồm các mục có số > 0 */
    public function for(User $user): array
    {
        $out = [];
        if ($this->auth->allows($user, Permission::QuanLyDoiXe)) {
            $out['fleet'] = $this->fleet->openDiscrepancies();
        }
        if ($this->auth->allows($user, Permission::XemPhieu)) {
            $out['vouchers'] = (int)($this->vouchers->statusCounts($this->auth->visibleWarehouseIds($user))['DRAFT'] ?? 0);
        }
        return array_filter($out);
    }
}
