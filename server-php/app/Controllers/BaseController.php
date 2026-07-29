<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * Application base controller.
 *
 * Phase 8 Intelligence controllers extend this class. It aliases BaseApiController
 * so they share auth helpers and JSON response conventions without duplicating
 * the CI4 scaffold file that was never committed to this repo.
 */
abstract class BaseController extends BaseApiController
{
}
