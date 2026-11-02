<?php

declare(strict_types=1);

namespace Lumineoss\Paystart;

/**
 * Package entry point.
 *
 * Kept intentionally zero-argument for backward compatibility with the
 * previously published identity of this package. Composition happens through
 * the concrete bridge (Laravel, Symfony, or standalone), which builds the
 * object graph from configuration and hands it to the application layer.
 */
final class Paystart
{
    public function __construct()
    {
    }
}
