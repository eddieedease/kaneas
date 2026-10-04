<?php

declare(strict_types=1);

namespace Kaneas;

use Kaneas\Controllers\AdminController;
use Kaneas\Controllers\AuthController;
use Kaneas\Controllers\BoardController;
use Kaneas\Controllers\CardController;
use Kaneas\Controllers\ColumnController;
use Kaneas\Controllers\MemberController;
use Kaneas\Core\Config;
use Kaneas\Core\HttpException;
use Kaneas\Core\Request;
use Kaneas\Core\Response;
use Kaneas\Core\Router;

final class App
{
    public static function run(): void
    {
        self::sendSecurityHeaders();

        try {
            if (!Config::isInstalled()) {
                throw new HttpException(503, 'not_installed');
            }
            Config::load();
            $response = self::routes()->dispatch(Request::fromGlobals());
        } catch (HttpException $e) {
            $body = ['error' => $e->errorCode];
            if ($e->details) {
                $body['details'] = $e->details;
            }
            $response = new Response($body, $e->status);
        } catch (\Throwable $e) {
            error_log('[kaneas] ' . $e);
            $body = ['error' => 'server_error'];
            if (Config::isLoaded() && Config::get('app.debug', false)) {
                $body['message'] = $e->getMessage();
            }
            $response = new Response($body, 500);
        }

        $response->send();
    }

    private static function routes(): Router
    {
        $r = new Router();

        // Auth
        $r->add('POST', 'auth/register', [AuthController::class, 'register'], Router::PUBLIC);
        $r->add('POST', 'auth/login', [AuthController::class, 'login'], Router::PUBLIC);
        $r->add('POST', 'auth/refresh', [AuthController::class, 'refresh'], Router::PUBLIC);
        $r->add('POST', 'auth/logout', [AuthController::class, 'logout'], Router::PUBLIC);
        $r->add('GET', 'auth/config', [AuthController::class, 'config'], Router::PUBLIC);
        $r->add('GET', 'auth/me', [AuthController::class, 'me']);
        $r->add('PATCH', 'auth/me', [AuthController::class, 'updateMe']);
        $r->add('POST', 'auth/password', [AuthController::class, 'changePassword']);

        // Boards
        $r->add('GET', 'boards', [BoardController::class, 'index']);
        $r->add('POST', 'boards', [BoardController::class, 'store']);
        $r->add('GET', 'boards/{id}', [BoardController::class, 'show']);
        $r->add('PATCH', 'boards/{id}', [BoardController::class, 'update']);
        $r->add('DELETE', 'boards/{id}', [BoardController::class, 'destroy']);

        // Columns
        $r->add('POST', 'boards/{id}/columns', [ColumnController::class, 'store']);
        $r->add('PUT', 'boards/{id}/columns/order', [ColumnController::class, 'reorder']);
        $r->add('PATCH', 'columns/{id}', [ColumnController::class, 'update']);
        $r->add('DELETE', 'columns/{id}', [ColumnController::class, 'destroy']);

        // Cards
        $r->add('POST', 'columns/{id}/cards', [CardController::class, 'store']);
        $r->add('PATCH', 'cards/{id}', [CardController::class, 'update']);
        $r->add('POST', 'cards/{id}/move', [CardController::class, 'move']);
        $r->add('DELETE', 'cards/{id}', [CardController::class, 'destroy']);

        // Collaboration
        $r->add('GET', 'boards/{id}/members', [MemberController::class, 'index']);
        $r->add('POST', 'boards/{id}/members', [MemberController::class, 'store']);
        $r->add('PATCH', 'boards/{id}/members/{userId}', [MemberController::class, 'update']);
        $r->add('DELETE', 'boards/{id}/members/{userId}', [MemberController::class, 'destroy']);
        $r->add('DELETE', 'boards/{id}/invitations/{invitationId}', [MemberController::class, 'destroyInvitation']);

        // Admin
        $r->add('GET', 'admin/users', [AdminController::class, 'users'], Router::ADMIN);
        $r->add('PATCH', 'admin/users/{id}', [AdminController::class, 'updateUser'], Router::ADMIN);
        $r->add('DELETE', 'admin/users/{id}', [AdminController::class, 'deleteUser'], Router::ADMIN);
        $r->add('GET', 'admin/settings', [AdminController::class, 'settings'], Router::ADMIN);
        $r->add('PATCH', 'admin/settings', [AdminController::class, 'updateSettings'], Router::ADMIN);
        $r->add('GET', 'admin/settings/mail', [AdminController::class, 'mailSettings'], Router::ADMIN);
        $r->add('PUT', 'admin/settings/mail', [AdminController::class, 'updateMailSettings'], Router::ADMIN);
        $r->add('POST', 'admin/settings/mail/test', [AdminController::class, 'testMail'], Router::ADMIN);

        return $r;
    }

    private static function sendSecurityHeaders(): void
    {
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
        header('Cache-Control: no-store');
    }
}
