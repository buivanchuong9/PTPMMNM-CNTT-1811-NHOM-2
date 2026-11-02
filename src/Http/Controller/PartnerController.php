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
use StockFlow\Service\PartnerService;

/** Quản lý đối tác: nhà cung cấp & khách hàng (xem: ai có quyền xem danh mục; sửa: tài khoản cấp cao). */
final class PartnerController extends Controller
{
    public function __construct(View $view, Session $session, Authorizer $auth, private PartnerService $partners, private \StockFlow\Service\CatalogService $catalog, private \StockFlow\Core\Db $db)
    {
        parent::__construct($view, $session, $auth);
    }

    public function index(Request $r, array $p, ?User $user): Response
    {
        $this->need($user, Permission::XemDanhMuc);
        $all = $this->partners->all($user?->isAdmin ? null : ($user?->warehouseId ?? 0));   // quản trị thấy tất cả; kho chỉ thấy khách của kho mình
        $type = isset(PartnerService::TYPES[$r->str('type')]) ? $r->str('type') : '';
        $q = fold_vn($r->search('q'));   // không phân biệt hoa thường và dấu
        $counts = ['' => count($all), 'SUPPLIER' => 0, 'CUSTOMER' => 0];
        foreach ($all as $x) {
            $counts[$x['loai']]++;
        }
        // lọc thêm: trạng thái hợp tác · danh mục hàng cung cấp (id · 'none' = hàng chưa phân loại) · một mặt hàng cụ thể (chỉ nhà cung cấp có gắn hàng)
        $status = in_array($r->str('status'), ['active', 'inactive'], true) ? $r->str('status') : '';
        $catRaw = $r->str('category');
        $category = $catRaw === 'none' ? -1 : (int)$catRaw;
        $activeItems = array_values(array_filter($this->catalog->items(), fn($i) => $i['hoat_dong']));
        $catOf = array_column($this->catalog->items(), 'danh_muc_id', 'id');
        $itemOptions = array_values(array_filter($activeItems, fn($i) => $category === 0 || ($category === -1 ? !(int)$i['danh_muc_id'] : (int)$i['danh_muc_id'] === $category)));
        $item = (int)$r->int('item');
        $item = $item && in_array($item, array_map(fn($i) => (int)$i['id'], $itemOptions), true) ? $item : 0;   // đổi danh mục thì bỏ mặt hàng không còn thuộc danh mục
        $supplies = fn(array $x) => ($item === 0 || in_array($item, array_column($x['items'], 'id'), true))
            && ($category === 0 || array_filter($x['items'], fn($it) => $category === -1 ? !(int)($catOf[$it['id']] ?? 0) : (int)($catOf[$it['id']] ?? 0) === $category));
        $rows = array_values(array_filter($all, fn($x) => ($type === '' || $x['loai'] === $type)
            && ($status === '' || ($status === 'active') === (bool)$x['hoat_dong'])
            && ($item === 0 && $category === 0 || $supplies($x))
            && ($q === '' || str_contains(fold_vn($x['ma'] . ' ' . $x['ten'] . ' ' . $x['nguoi_lien_he'] . ' ' . $x['so_dien_thoai'] . ' ' . $x['email'] . ' ' . implode(' ', array_column($x['items'], 'sku'))), $q))));
        [$rows, $pg] = Paginator::of($rows, $r);
        return $this->render('partners/index', $user, 'partners', ['rows' => $rows, 'pg' => $pg, 'type' => $type, 'q' => $r->search('q'), 'status' => $status, 'category' => $category, 'item' => $item, 'itemOptions' => $itemOptions, 'catOf' => $catOf, 'counts' => $counts, 'types' => PartnerService::TYPES, 'canEdit' => $this->partners->canEdit($user), 'canApprove' => $user && $this->partners->canApprove($user), 'units' => $this->catalog->units(), 'categories' => $this->catalog->categories(), 'items' => array_values(array_filter($this->catalog->items(), fn($i) => $i['hoat_dong']))], 'Đối tác');
    }

    private function fields(Request $r): array
    {
        return [$r->str('code'), $r->str('name'), $r->str('type'), $r->str('contact'), $r->str('phone'), $r->str('email'), \StockFlow\Domain\VnAddress::composeLoose($r->str('street'), $r->str('ward'), $r->str('province')) ?: $r->str('address'), $r->str('note')];
    }

    /** Các dòng «hàng mới do nhà cung cấp cung cấp» trên form: [sku, tên, đơn vị, danh mục, theo lô]. */
    private function newItems(Request $r): array
    {
        $out = [];
        $names = $r->arr('new_name');
        $units = $r->arr('new_unit');
        $cats = $r->arr('new_cat');
        $lots = $r->arr('new_lot');
        foreach ($r->arr('new_sku') as $i => $sku) {
            $name = trim((string)($names[$i] ?? ''));
            $sku = trim((string)$sku);
            if ($sku === '' && $name === '') {
                continue;
            }
            if ($sku === '' || $name === '') {
                throw new \StockFlow\Domain\DomainError('Mỗi hàng mới cần có đủ SKU và tên hàng');
            }
            $cat = (int)($cats[$i] ?? 0) ?: null;   // 0 / bỏ trống = chưa có danh mục (admin phân loại sau)
            $out[] = [$sku, $name, (int)($units[$i] ?? 0), $cat, ($lots[$i] ?? '0') === '1'];
        }
        return $out;
    }

    /** Tạo các mặt hàng mới, trả về id (cùng id hàng có sẵn đã tick) để gắn vào nhà cung cấp. @return list<int> */
    private function createNewItems(Request $r, User $user, array $existing): array
    {
        $new = $this->newItems($r);
        if ($new && $r->str('type') !== 'SUPPLIER') {
            throw new \StockFlow\Domain\DomainError('Chỉ nhà cung cấp mới có mặt hàng cung cấp');
        }
        $ids = array_map('intval', $existing);
        foreach ($new as [$sku, $name, $unit, $cat, $lot]) {
            $ids[] = $this->catalog->addItem($user, $sku, $name, $unit, $lot, 0, 1, $cat, true);
        }
        return $ids;
    }

    public function add(Request $r, array $p, ?User $user): Response
    {
        return $this->act(function () use ($r, $user) {
            $this->db->transaction(function () use ($r, $user) {   // đối tác + hàng mới lưu cùng lúc, lỗi một phần thì không lưu gì
                $supplier = $r->str('type') === 'SUPPLIER';
                $id = $this->partners->add($user, ...$this->fields($r));
                $ids = $this->createNewItems($r, $user, $supplier ? $r->arr('item_ids') : []);
                $this->partners->setPartnerItems($user, $id, $supplier ? $ids : []);
            });
        }, $user->isAdmin ? 'Đã thêm đối tác.' : 'Đã gửi đối tác — chờ quản trị duyệt mới dùng được.', '/partners');
    }

    public function update(Request $r, array $p, ?User $user): Response
    {
        return $this->act(function () use ($r, $user) {
            $this->db->transaction(function () use ($r, $user) {
                $supplier = $r->str('type') === 'SUPPLIER';
                $id = (int)$r->int('partner_id');
                $this->partners->update($user, $id, ...$this->fields($r));
                $ids = $this->createNewItems($r, $user, $supplier ? $r->arr('item_ids') : []);
                $this->partners->setPartnerItems($user, $id, $supplier ? $ids : []);
            });
        }, 'Đã lưu thông tin đối tác.', '/partners');
    }

    public function approve(Request $r, array $p, ?User $user): Response
    {
        return $this->act(fn() => $this->partners->approve($user, (int)$r->int('partner_id')), 'Đã duyệt đối tác — dùng được trong phiếu.', '/partners');
    }

    public function reject(Request $r, array $p, ?User $user): Response
    {
        return $this->act(fn() => $this->partners->reject($user, (int)$r->int('partner_id')), 'Đã từ chối và xoá đối tác chờ duyệt.', '/partners');
    }

    public function active(Request $r, array $p, ?User $user): Response
    {
        $on = $r->str('active') === '1';
        return $this->act(fn() => $this->partners->setActive($user, (int)$r->int('partner_id'), $on), $on ? 'Đã dùng lại đối tác.' : 'Đã ngừng hợp tác — không còn gợi ý trong phiếu.', '/partners');
    }
}
