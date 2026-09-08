<?php
/**
 * Layout wrapper for template_include views that use theme header/footer
 * or a custom master layout.
 *
 * WordPress includes this file via template_include. The active ViewContext
 * holds the real view path and data; this stub renders content and wraps it.
 *
 * @package    Framework
 * @subpackage View
 * @since      2.1.2
 */

defined('ABSPATH') || exit;

use Framework\View\SectionManager;
use Framework\View\TemplateEngine;
use Framework\View\ViewContext;

use function Framework\app;

$framework_context = app(ViewContext::class);
$framework_active = $framework_context->get_active();

if ($framework_active === null || empty($framework_active['resolved_path'])) {
    return;
}

$framework_path = $framework_active['resolved_path'];
$framework_engine = app(TemplateEngine::class);

// Master layout: child populates sections, then master layout renders around them.
if (!empty($framework_active['master_layout'])) {
    $framework_master_path = $framework_engine->resolve_path($framework_active['master_layout']);

    if ($framework_master_path === '') {
        return;
    }

    $framework_sections = app(SectionManager::class);
    $framework_sections->clear();

    // Execute the child template to populate sections.
    ob_start();
    require $framework_path;
    ob_end_clean();

    // Render the master layout which yields the captured sections.
    ob_start();
    require $framework_master_path;
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Assembled layout HTML; dynamic data is escaped in view templates via esc_*.
    echo (string) ob_get_clean();

    $framework_sections->clear();

    return;
}

// Standard theme layout: wrap with header/footer.
ob_start();
require $framework_path;
$framework_content = (string) ob_get_clean();

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Assembled layout HTML; dynamic data is escaped in view templates via esc_*.
echo $framework_engine->wrap_layout($framework_content);
