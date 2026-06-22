<?php

namespace WindowsForum\SessionValidator\XF\ControllerPlugin;

use XF\Entity\User;
use XF\Entity\UserRemember;
use XF\Repository\UserRememberRepository;

class LoginPlugin extends XFCP_LoginPlugin
{
    protected const SESSION_AUTH_REMEMBER_LIFETIME = 14400;

    public function completeLogin(User $user, $remember)
    {
        $result = parent::completeLogin($user, $remember);

        if (!$remember)
        {
            $this->createSessionAuthRememberCookie($user);
        }

        return $result;
    }

    protected function createSessionAuthRememberCookie(User $user)
    {
        if (!$user->user_id)
        {
            return;
        }

        /** @var UserRemember $remember */
        $remember = \XF::em()->create(UserRemember::class);
        $key = $remember->generateForUserId($user->user_id);
        $remember->extendExpiryDate(self::SESSION_AUTH_REMEMBER_LIFETIME);
        $remember->save();

        /** @var UserRememberRepository $rememberRepo */
        $rememberRepo = $this->repository(UserRememberRepository::class);
        $value = $rememberRepo->getCookieValue($user->user_id, $key);

        $this->app->response()->setCookie('user', $value, 0);
    }
}
