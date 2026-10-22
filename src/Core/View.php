<?php
declare(strict_types=1);

namespace StockFlow\Core;

/** Render template PHP trong templates/ vào layout chung. */
final class View
{
    public function __construct(private Session $session) {}

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = [], string $layout = 'layout/app', int $status = 200): Response
    {
        $data += ['flash' => $this->session->takeFlash(), 'csrf' => $this->session->csrf(), 'compact' => (bool)$this->session->pref('compact')];
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
