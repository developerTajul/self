<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Api\CompanyController;
use Framework\Routing\Middleware\RequireCapability;

/**
 * Rejects the request unless the caller may manage companies.
 *
 * A subclass rather than a parameter, because the framework resolves a REST
 * route's middleware by class name only: `Middleware::class . ':capability'`
 * registers a route whose middleware cannot be resolved. That failure is
 * quiet - the resolution throws, the adapter converts the throw into a denial,
 * and the endpoint 403s for every caller including administrators.
 *
 * `manage_options` is the capability the module's admin screens already use
 * ({@see CompanyController::CAPABILITY}), so the API and the screens admit the
 * same population. Letting the two diverge would mean a user who can edit a
 * company in wp-admin being refused by the endpoint that edits it.
 */
final class RequireCompanyManage extends RequireCapability
{
    public function __construct()
    {
        parent::__construct(CompanyController::CAPABILITY);
    }
}
