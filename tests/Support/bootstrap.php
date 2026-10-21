<?php
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

use StockFlow\Core\Container;
use StockFlow\Core\Config;
use StockFlow\Core\Db;
use StockFlow\Domain\Role;
use StockFlow\Domain\User;
use StockFlow\Service\CatalogService;

$GLOBALS['t_total'] = 0;
$GLOBALS['t_failed'] = 0;

function same(mixed $actual, mixed $expected, string $name): void
{
    $GLOBALS['t_total']++;
    if ($actual === $expected) {
        echo "  ✔ $name\n";
        return;
    }
    $GLOBALS['t_failed']++;
    echo "  ✘ $name\n      mong đợi=" . var_export($expected, true) . "\n      thực tế=" . var_export($actual, true) . "\n";
}

function truthy(mixed $cond, string $name): void
{
    same((bool)$cond, true, $name);
}

/** Khẳng định $fn ném đúng lớp ngoại lệ (hoặc lớp con). */
function throws(callable $fn, string $class, string $name): void
{
    $GLOBALS['t_total']++;
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            echo "  ✔ $name\n";
            return;
        }
        $GLOBALS['t_failed']++;
        echo "  ✘ $name\n      mong đợi ngoại lệ $class, nhận " . $e::class . ': ' . $e->getMessage() . "\n";
        return;
    }
    $GLOBALS['t_failed']++;
    echo "  ✘ $name\n      mong đợi ngoại lệ $class nhưng không có lỗi nào\n";
}

function section(string $title): void
{
    echo "\n$title\n";
}

function finish(): never
{
    echo "TESTS={$GLOBALS['t_total']} FAILED={$GLOBALS['t_failed']}\n";
    exit($GLOBALS['t_failed'] === 0 ? 0 : 1);
}

/** Dữ liệu kiểm thử độc lập (hậu tố ngẫu nhiên): 2 kho A/B, người dùng theo quyền, 3 mặt hàng (1 có lô). */
final class Fx
{
    public const PASSWORD = 'test-pass-123';

    public Container $c;
    public Db $db;
    public string $tag;
    public int $wa;
    public int $wb;
    public User $admin;
    public User $keeperA;
    public User $approverA;
    public User $keeperB;
    public User $approverB;
    public User $both;   // KEEPER+APPROVER+COUNTER ở cả A và B
    /** @var array<string,array{item:int,batch:int,sku:string}> */
    public array $it = [];

    public static function make(): self
    {
        $f = new self();
        $f->db = Db::connect(Config::database());
        $f->c = new Container();
        $f->c->set(Db::class, $f->db);
        $f->tag = strtoupper(bin2hex(random_bytes(3)));
        $cat = $f->c->get(CatalogService::class);
        $hash = password_hash(self::PASSWORD, PASSWORD_DEFAULT);
        $mk = fn(string $name, bool $admin = false) => new User(
            $f->db->insert('INSERT INTO nguoi_dung (username, full_name, password_hash, is_admin) VALUES (?,?,?,?)', ["{$name}_{$f->tag}", "Test $name", $hash, (int)$admin]),
            "{$name}_{$f->tag}", "Test $name", $admin);
        $f->admin = $mk('admin', true);
        $f->wa = $cat->addWarehouse($f->admin, "A{$f->tag}", 'Kho test A', '');
        $f->wb = $cat->addWarehouse($f->admin, "B{$f->tag}", 'Kho test B', '');
        $f->keeperA = $mk('keeperA');
        $f->approverA = $mk('approverA');
        $f->keeperB = $mk('keeperB');
        $f->approverB = $mk('approverB');
        $f->both = $mk('both');
        $cat->grant($f->admin, $f->keeperA->id, $f->wa, Role::Keeper);
        $cat->grant($f->admin, $f->keeperA->id, $f->wa, Role::Counter);
        $cat->grant($f->admin, $f->approverA->id, $f->wa, Role::Approver);
        $cat->grant($f->admin, $f->keeperB->id, $f->wb, Role::Keeper);
        $cat->grant($f->admin, $f->keeperB->id, $f->wb, Role::Counter);
        $cat->grant($f->admin, $f->approverB->id, $f->wb, Role::Approver);
        foreach ([$f->wa, $f->wb] as $w) {
            foreach (Role::cases() as $r) {
                $cat->grant($f->admin, $f->both->id, $w, $r);
            }
        }
        $unit = $cat->addUnit($f->admin, "u{$f->tag}", 'Đơn vị test');
        foreach (['x' => false, 'y' => false, 'lot' => true] as $k => $tracked) {
            $sku = strtoupper($k) . "-{$f->tag}";
            $id = $cat->addItem($f->admin, $sku, "Hàng test $k", $unit, $tracked, 0);
            $batch = $tracked ? $cat->addBatch($f->admin, $id, 'L1', '2030-01-01') : (int)$f->db->value('SELECT id FROM lo_hang WHERE item_id = ?', [$id]);
            $f->it[$k] = ['item' => $id, 'batch' => $batch, 'sku' => $sku];
        }
        return $f;
    }

    public function line(string $k, int $qty): array
    {
        return ['item_id' => $this->it[$k]['item'], 'batch_id' => $this->it[$k]['batch'], 'qty' => $qty];
    }

    /** Tồn tính từ sổ phát sinh. */
    public function stock(int $wh, string $k): int
    {
        return (int)$this->db->value('SELECT COALESCE(SUM(qty_change),0) FROM so_phat_sinh WHERE warehouse_id = ? AND item_id = ? AND batch_id = ?',
            [$wh, $this->it[$k]['item'], $this->it[$k]['batch']]);
    }

    public function balance(int $wh, string $k): int
    {
        return (int)$this->db->value('SELECT COALESCE(SUM(quantity),0) FROM ton_kho WHERE warehouse_id = ? AND item_id = ? AND batch_id = ?',
            [$wh, $this->it[$k]['item'], $this->it[$k]['batch']]);
    }

    public function movements(int $wh): int
    {
        return (int)$this->db->value('SELECT COUNT(*) FROM so_phat_sinh WHERE warehouse_id = ?', [$wh]);
    }

    /** Nhập hàng nhanh: lập + duyệt phiếu nhập tại kho. */
    public function receive(int $wh, string $k, int $qty): array
    {
        $svc = $this->c->get(\StockFlow\Service\VoucherService::class);
        $u = $wh === $this->wa ? [$this->keeperA, $this->approverA] : [$this->keeperB, $this->approverB];
        $v = $svc->createDraft($u[0], \StockFlow\Domain\VoucherType::Receipt, $wh, [$this->line($k, $qty)], 'NCC test', '', null)['voucher'];
        $svc->confirm($u[1], (int)$v['id']);
        return $v;
    }

    public function draftIssue(int $wh, string $k, int $qty): array
    {
        $keeper = $wh === $this->wa ? $this->keeperA : $this->keeperB;
        return $this->c->get(\StockFlow\Service\VoucherService::class)
            ->createDraft($keeper, \StockFlow\Domain\VoucherType::Issue, $wh, [$this->line($k, $qty)], 'Khách test', '', null)['voucher'];
    }
}

/**
 * Chạy song song nhiều tiến trình PHP (mỗi tiến trình một kết nối CSDL riêng) với một "vạch xuất phát" chung.
 * @param list<array<string,mixed>> $jobs @return list<array<string,mixed>> kết quả theo thứ tự jobs
 */
function runParallel(array $jobs): array
{
    $gate = sys_get_temp_dir() . '/sf-gate-' . bin2hex(random_bytes(6));
    $procs = [];
    foreach ($jobs as $i => $job) {
        $job['gate'] = $gate;
        $p = proc_open([PHP_BINARY, __DIR__ . '/worker.php', json_encode($job)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null);
        $procs[$i] = [$p, $pipes];
    }
    usleep(400_000);        // để mọi worker kết nối xong rồi mới thả vạch xuất phát
    touch($gate);
    $out = [];
    foreach ($procs as $i => [$p, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        proc_close($p);
        $decoded = json_decode(trim((string)$stdout), true);
        $out[$i] = is_array($decoded) ? $decoded : ['status' => 'crash', 'msg' => trim($stdout . $stderr)];
    }
    @unlink($gate);
    return $out;
}
