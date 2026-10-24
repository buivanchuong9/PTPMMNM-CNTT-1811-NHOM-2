<?php
declare(strict_types=1);

namespace StockFlow\Core;

/** Render template PHP trong templates/ vào layout chung. */
final class View
{
    /** @var ?\Closure():array<string,int> tính số nhỏ hiện cạnh mục menu (khoá = tên mục) — chỉ gọi khi dựng khung trang */
    private ?\Closure $badges = null;

    /** @var ?\Closure():array<string,int> số thay đổi mới của từng mục menu */
    private ?\Closure $newCounts = null;
    /** @var array{section:string,ids:array<int,true>,after:int,upto:int}|null phần «thay đổi mới» cần bôi đậm ở trang danh sách đang mở */
    private ?array $changes = null;

    public function __construct(private Session $session) {}

    public function setNewCounts(\Closure $fn): void
    {
        $this->newCounts = $fn;
    }

    /** @param array{section:string,ids:array<int,true>,after:int,upto:int} $changes */
    public function setChanges(array $changes): void
    {
        $this->changes = $changes;
    }

    public function setBadges(\Closure $fn): void
    {
        $this->badges = $fn;
    }

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = [], string $layout = 'layout/app', int $status = 200): Response
    {
        $data += ['flash' => $this->session->takeFlash(), 'old' => $this->session->takeOld(), 'csrf' => $this->session->csrf(), 'compact' => (bool)$this->session->pref('compact'),
            'welcome' => $layout === 'layout/app' && $this->session->takeWelcome(),
            'navBadges' => $layout === 'layout/app' && $this->badges ? ($this->badges)() : [],
            'navNew' => $layout === 'layout/app' && $this->newCounts ? ($this->newCounts)() : [],
            'changes' => $this->changes ?? ['section' => '', 'ids' => [], 'after' => 0, 'upto' => 0]];
        $content = $this->capture($template, $data);
        return new Response($layout === '' ? $content : $this->capture($layout, $data + ['content' => $content]), $status);
    }

    private function capture(string $template, array $data): string
    {
        $file = Config::root() . '/templates/' . $template . '.php';
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $file;
            return (string)ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}
