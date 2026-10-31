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
    public function __construct(private Authorizer $auth, private FleetService $fleet, private VoucherRepository $vouchers, private TransferRequestService $requests, private ItemProposalService $proposals, private WarrantyService $warranty, private DisposalService $disposals, private StockQuery $stock) {}

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
        if ($this->auth->allows($user, Permission::XuLyBaoHanh)) {   // hàng khách gửi đang chờ sửa / chờ trả
            $out['warranty'] = $this->warranty->open($this->auth->visibleWarehouseIds($user));
        }
        if ($this->auth->allows($user, Permission::XacNhanHuy)) {   // đề xuất huỷ hàng chờ quản lý duyệt
            $out['disposals'] = $this->disposals->counts($this->auth->visibleWarehouseIds($user))['CHO_DUYET'] ?? 0;
        }
        $out['proposals'] = $this->proposals->attention($user);
        return array_filter($out);
    }

    /**
     * Việc đang chờ xử lý, hiện ở đầu Tổng quan. Chỉ gồm mục có số > 0.
     * @return list<array{0:string,1:int,2:string}> [nhãn, số, đường dẫn]
     */
    public function todo(User $user): array
    {
        $b = $this->for($user);
        $delivering = $this->auth->allows($user, Permission::XemPhieu) ? $this->vouchers->deliveringCount($this->auth->visibleWarehouseIds($user)) : 0;
        $expired = $soon = 0;
        if ($this->auth->allows($user, Permission::XemTonKho)) {   // lô còn tồn đã hết hạn / sắp hết hạn trong EXPIRY_WARN_DAYS ngày
            foreach ($this->stock->expiring($this->auth->visibleWarehouseIds($user)) as $e) {
                $e['status'] === 'expired' ? $expired++ : $soon++;
            }
        }
        $items = [
            ['lô đã hết hạn còn tồn', $expired, '/inventory?view=batch&expiry=expired'],
            ['lô sắp hết hạn (' . StockQuery::EXPIRY_WARN_DAYS . ' ngày)', $soon, '/inventory?view=batch&expiry=soon'],
            ['phiếu chờ xác nhận', (int)($b['vouchers'] ?? 0), '/vouchers?status=DRAFT'],
            ['đơn đang giao', $delivering, '/vouchers?type=ISSUE&status=CONFIRMED'],
            ['phiếu bảo hành đang xử lý', (int)($b['warranty'] ?? 0), '/warranty'],
            ['yêu cầu chuyển hàng', (int)($b['requests'] ?? 0), '/requests'],
            ['đề xuất huỷ hàng', (int)($b['disposals'] ?? 0), '/disposals'],
            ['chuyến xe báo sai lệch', (int)($b['fleet'] ?? 0), '/fleet'],
            ['đề xuất hàng mới', (int)($b['proposals'] ?? 0), '/proposals'],
        ];
        return array_values(array_filter($items, fn($i) => $i[1] > 0));
    }
}
