<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Controller\ErrorController;
use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\UnauthenticatedException;
use App\Core\Request;
use App\Core\Session;

Session::start();

/** @var array{router: App\Core\Router, auth: App\Core\Auth} $app */
$app = require __DIR__ . '/../config/bootstrap.php';

$request = new Request();
$errorController = new ErrorController($app['auth']);

try {
    echo $app['router']->dispatch($request->method(), $request->path(), $request);
} catch (UnauthenticatedException) {
    header('Location: /login');
} catch (ForbiddenException) {
    echo $errorController->forbidden();
} catch (NotFoundException) {
    echo $errorController->notFound();
} catch (\Throwable $e) {
    error_log($e->getMessage() . "\n" . $e->getTraceAsString());
    echo $errorController->serverError();
}
