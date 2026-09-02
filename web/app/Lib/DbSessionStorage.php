<?php

declare(strict_types=1);

namespace App\Lib;

use App\Models\Session as SessionModel;
use Illuminate\Support\Facades\Log;
use Shopify\Auth\AccessTokenOnlineUserInfo;
use Shopify\Auth\Session;
use Shopify\Auth\SessionStorage;
use Throwable;

/**
 * Persists Shopify sessions in MySQL through Eloquent.
 */
class DbSessionStorage implements SessionStorage
{
    public function loadSession(string $sessionId): ?Session
    {
        $row = SessionModel::where('session_id', $sessionId)->first();

        if (! $row) {
            return null;
        }

        $session = new Session($row->session_id, $row->shop, (bool) $row->is_online, (string) $row->state);

        if ($row->expires_at) {
            $session->setExpires($row->expires_at);
        }
        if ($row->access_token) {
            $session->setAccessToken($row->access_token);
        }
        if ($row->scope) {
            $session->setScope($row->scope);
        }
        if ($row->user_id) {
            $session->setOnlineAccessInfo(new AccessTokenOnlineUserInfo(
                (int) $row->user_id,
                $row->user_first_name,
                $row->user_last_name,
                $row->user_email,
                (bool) $row->user_email_verified,
                (bool) $row->account_owner,
                $row->locale,
                (bool) $row->collaborator,
            ));
        }

        return $session;
    }

    public function storeSession(Session $session): bool
    {
        $row = SessionModel::firstOrNew(['session_id' => $session->getId()]);

        $row->shop = $session->getShop();
        $row->state = $session->getState();
        $row->is_online = $session->isOnline();
        $row->access_token = $session->getAccessToken();
        $row->expires_at = $session->getExpires();
        $row->scope = $session->getScope();

        if ($info = $session->getOnlineAccessInfo()) {
            $row->user_id = $info->getId();
            $row->user_first_name = $info->getFirstName();
            $row->user_last_name = $info->getLastName();
            $row->user_email = $info->getEmail();
            $row->user_email_verified = $info->isEmailVerified();
            $row->account_owner = $info->isAccountOwner();
            $row->locale = $info->getLocale();
            $row->collaborator = $info->isCollaborator();
        }

        try {
            return $row->save();
        } catch (Throwable $e) {
            Log::error('Failed to store Shopify session: '.$e->getMessage());

            return false;
        }
    }

    public function deleteSession(string $sessionId): bool
    {
        return SessionModel::where('session_id', $sessionId)->delete() > 0;
    }
}
