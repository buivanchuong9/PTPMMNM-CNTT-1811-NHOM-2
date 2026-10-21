<?php
declare(strict_types=1);

namespace StockFlow\Domain;

use RuntimeException;

/** Lỗi nghiệp vụ có thể hiển thị cho người dùng (HTTP 422). */
class DomainError extends RuntimeException {}
