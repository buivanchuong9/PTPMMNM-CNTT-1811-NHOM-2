<?php
declare(strict_types=1);

namespace StockFlow\Domain;

use RuntimeException;

/** Vi phạm quyền theo kho (HTTP 403). */
final class Forbidden extends RuntimeException {}
