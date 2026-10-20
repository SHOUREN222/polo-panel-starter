<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\QueuedResetPassword;
use App\Services\UserAccessRevoker;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

// is_admin, referred_by_user_id, invite_code_id and the telegram_* fields
// are deliberately excluded — none should be settable via mass assignment.
// is_admin only via factory/seeder or trusted admin-only code;
// referred_by_user_id/invite_code_id only via the registration flow after
// validating an invite code; telegram_id only via a verified bind-code
// exchange in TelegramBotPoll, never from arbitrary form input.
#[Fillable(['name', 'email', 'password', 'balance', 'banned', 'plan_id', 'plan_expired_at', 'shared_traffic_reset_at', 'notify_expire', 'notify_traffic'])]
#[Hidden(['password', 'remember_token', 'token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $attributes = [
        'balance' => 0,
        'banned' => false,
        'is_admin' => false,
        'is_owner' => false,
        'notify_expire' => true,
        'notify_traffic' => true,
    ];

    /**
     * Emails are stored lowercased. SQLite compares strings case-sensitively,
     * so a customer who registered as "Me@QQ.com" could not log in, reset their
     * password, or use a magic link by typing "me@qq.com" — every lookup is an
     * exact `where('email', …)` (directly, or inside Auth::attempt / the
     * password broker) and silently found nobody. Normalizing on write here
     * covers every path that creates a user (registration, API, admin, tenant
     * seeding); the lookup sites lowercase the *input* to match.
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value === null ? null : strtolower(trim($value)),
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'banned' => 'boolean',
            'balance' => 'integer',
            'plan_expired_at' => 'datetime',
            'last_login_at' => 'datetime',
            'shared_traffic_reset_at' => 'date',
            'is_admin' => 'boolean',
            'is_owner' => 'boolean',
            'is_agent' => 'boolean',
            'agent_discount' => 'integer',
            'notify_expire' => 'boolean',
            'notify_traffic' => 'boolean',
            'telegram_bind_code_expires_at' => 'datetime',
            // Encrypted at rest so a DB leak doesn't hand over 2FA seeds /
            // recovery codes. Not fillable — only TwoFactorSettings writes them.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** 2FA is only "on" once the user has confirmed setup with a valid code. */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->uuid ??= (string) Str::uuid();
            $user->token ??= Str::random(48);

            // Trusted legacy seeders/factories created admins before roles
            // existed. Keep those explicit is_admin creations compatible, but
            // fail closed if a persisted account later has an invalid role.
            if ($user->is_admin && $user->admin_role === null) {
                $user->admin_role = self::ROLE_SUPER;
            }
        });

        static::updated(function (User $user) {
            $authorizationChanged = $user->wasChanged(['password', 'is_admin', 'admin_role'])
                || ($user->wasChanged('banned') && $user->banned);

            if ($authorizationChanged) {
                app(UserAccessRevoker::class)->revoke($user);
            }
        });
    }

    /**
     * Use the queued reset-password notification so the "forgot password"
     * request doesn't block on SMTP — consistent with the app's other
     * transactional emails, which are all ShouldQueue.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new QueuedResetPassword($token));
    }

    public function subscriptionUrl(): string
    {
        $subscribeBase = Setting::get('subscribe_url', '');

        if ($subscribeBase) {
            $subscribePath = Setting::get('subscribe_path', 'subscribe') ?: 'subscribe';

            return rtrim($subscribeBase, '/').'/'.$subscribePath.'/'.$this->token;
        }

        return route('subscribe', ['token' => $this->token]);
    }

    /**
     * Shared-nodes-only subscription — used on the dashboard so a user
     * with just a shared plan (the common case) gets one real subscription
     * covering all of that plan's nodes, instead of copying each node's
     * link one at a time. subscriptionUrl() stays the "everything, shared
     * + dedicated instances together" one for accounts that have both.
     */
    public function sharedSubscriptionUrl(): string
    {
        $subscribeBase = Setting::get('subscribe_url', '');

        if ($subscribeBase) {
            $subscribePath = Setting::get('subscribe_path', 'subscribe') ?: 'subscribe';

            return rtrim($subscribeBase, '/').'/'.$subscribePath.'/'.$this->token.'/shared';
        }

        return route('subscribe.shared', ['token' => $this->token]);
    }

    /**
     * Single dedicated-ISP instance, as its own fetchable HTTP subscription
     * endpoint — lets one instance card offer the same one-click-import deep
     * links (clash://install-config?url=..., etc.) as the shared-plan card,
     * which only work against a URL a client can fetch, not a raw
     * ss://Xvless:// share link.
     */
    public function instanceSubscriptionUrl(SubscriptionInstance $instance): string
    {
        $subscribeBase = Setting::get('subscribe_url', '');

        if ($subscribeBase) {
            $subscribePath = Setting::get('subscribe_path', 'subscribe') ?: 'subscribe';

            return rtrim($subscribeBase, '/').'/'.$subscribePath.'/'.$this->token.'/instance/'.$instance->id;
        }

        return route('subscribe.instance', ['token' => $this->token, 'instance' => $instance->id]);
    }

    /**
     * Regenerate the subscription token/uuid, immediately invalidating any
     * previously issued subscription URL (e.g. after a leak).
     */
    public function resetSubscriptionCredentials(): void
    {
        $this->token = Str::random(48);
        $this->uuid = (string) Str::uuid();
        $this->save();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function subscriptionInstances(): HasMany
    {
        return $this->hasMany(SubscriptionInstance::class);
    }

    public function balanceTransactions(): HasMany
    {
        return $this->hasMany(BalanceTransaction::class);
    }

    /**
     * The single, audited way to move a user's balance. Applies the delta
     * (never letting balance go below zero), then records a BalanceTransaction
     * with the type/note/admin so every change is traceable. Returns the actual
     * delta applied (may be smaller than requested when a debit is capped at 0).
     * Callers should already be inside a DB transaction when atomicity matters.
     */
    public function changeBalance(int $delta, string $type, ?string $note = null, ?int $adminId = null): int
    {
        $before = (int) $this->balance;
        $after = max(0, $before + $delta);
        $applied = $after - $before;

        $this->forceFill(['balance' => $after])->save();

        $this->balanceTransactions()->create([
            'amount' => $applied,
            'balance_after' => $after,
            'type' => $type,
            'note' => $note,
            'admin_id' => $adminId,
        ]);

        return $applied;
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by_user_id');
    }

    public function inviteCodeUsed(): BelongsTo
    {
        return $this->belongsTo(InviteCode::class, 'invite_code_id');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(User::class, 'referred_by_user_id');
    }

    public function inviteCodes(): HasMany
    {
        return $this->hasMany(InviteCode::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }

    public function hasActiveSharedPlan(): bool
    {
        return $this->plan_id !== null
            && $this->plan_expired_at !== null
            && $this->plan_expired_at->isFuture();
    }

    public const ROLE_SUPER = 'super';

    public const ROLE_SUPPORT = 'support';

    /**
     * Admin sections a 客服 (support) role may open. Everything not listed —
     * 系统配置/支付配置/插件/主题/公告/知识库/服务器/节点/权限组/路由/套餐/
     * 优惠券/礼品卡 — is 超级管理员 only. Keyed by route name so both the
     * middleware gate and the sidebar filter read from one source of truth.
     */
    public const SUPPORT_ROUTES = [
        'admin.dashboard',
        'admin.users',
        'admin.tickets',
        'admin.orders',
        'admin.invites',
        'admin.profile',
    ];

    /**
     * Admin sections a 分站 (tenant) admin — the AGENT — may open on their own
     * subdomain. Only their own site's data: customers, orders, tickets,
     * invites, their own 优惠券/礼品卡, and their own payment gateway.
     * Platform sections (系统/节点/套餐目录/分站管理/知识库) are NEVER reachable
     * in a tenant context, even for a 分站-super admin.
     *
     * 'admin.notices' is deliberately absent: announcements are platform
     * infrastructure info (node maintenance, service changes) every customer
     * needs regardless of subdomain, so Notice always reads/writes the
     * central DB (see App\Models\Notice) — there's no per-分站 version to manage.
     */
    public const TENANT_ADMIN_ROUTES = [
        'admin.dashboard',
        'admin.branding',
        'admin.users',
        'admin.orders',
        'admin.tickets',
        'admin.invites',
        // 'admin.coupons' is deliberately absent: a coupon lowers
        // order->total_amount, and wholesale settles on total_amount
        // (WholesaleService::wholesaleCost) — so a 100%-off coupon would hand
        // out fully-provisioned service on the platform's nodes while debiting
        // the agent's 进货余额 nothing. 站长 sets prices; the agent's margin is
        // the wholesale discount. Do not add this back.
        'admin.giftcards', // safe: mints balance but never lowers total_amount, so wholesale still settles in full
        'admin.wholesale', // read-only: their own 进货余额 credit/debit history
        'admin.payments',
        'admin.profile',
    ];

    public function isAdmin(): bool
    {
        return $this->is_admin
            && in_array($this->admin_role, [self::ROLE_SUPER, self::ROLE_SUPPORT], true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->is_admin && $this->admin_role === self::ROLE_SUPER;
    }

    /**
     * The 站长 — the single account allowed to create or change 超级管理员s, and
     * which no one (not even another super admin) may demote. Everything else a
     * super admin can do; only minting/altering the super tier is owner-gated.
     */
    public function isOwner(): bool
    {
        return (bool) $this->is_owner;
    }

    public function isSupport(): bool
    {
        return $this->is_admin && $this->admin_role === self::ROLE_SUPPORT;
    }

    /**
     * A reseller: can generate gift cards from their own balance at a discount
     * (agent_discount %) and resell the codes. Distinct from admin — an agent
     * is a customer with the extra 代理中心, not backend access.
     */
    public function isAgent(): bool
    {
        return (bool) $this->is_agent;
    }

    /**
     * Gift cards this agent generated (their resale inventory).
     */
    public function agentGiftCards(): HasMany
    {
        return $this->hasMany(GiftCard::class, 'created_by_agent_id');
    }

    /**
     * Whether this admin may open a given admin route. Super admins can open
     * anything; support is limited to SUPPORT_ROUTES. Non-admins never reach
     * here (the `admin` middleware blocks them first), but we return false to
     * be safe. Used by both the route middleware and the sidebar renderer.
     */
    public function canAccessAdminRoute(?string $routeName): bool
    {
        if (! $this->isAdmin() || $routeName === null) {
            return false;
        }

        // In a 分站 (tenant) context this admin is the AGENT — scoped to their
        // own site's sections only, never the platform's (even if 分站-super).
        if (tenant()) {
            return in_array($routeName, self::TENANT_ADMIN_ROUTES, true);
        }

        if ($this->isSupport()) {
            return in_array($routeName, self::SUPPORT_ROUTES, true);
        }

        // Unknown/null roles fail closed instead of silently becoming super.
        return $this->isSuperAdmin();
    }

    public function adminRoleLabel(): string
    {
        return match (true) {
            $this->isOwner() => '站长',
            $this->isSupport() => '客服',
            $this->isSuperAdmin() => '超级管理员',
            default => '普通用户',
        };
    }

    public function isTelegramBound(): bool
    {
        return $this->telegram_id !== null;
    }

    /**
     * Generate a fresh single-use bind code (bypasses mass assignment, same
     * as resetSubscriptionCredentials — this is trusted, direct writes only).
     */
    public function generateTelegramBindCode(): string
    {
        $code = strtoupper(Str::random(8));

        $this->telegram_bind_code = $code;
        $this->telegram_bind_code_expires_at = now()->addMinutes(15);
        $this->save();

        return $code;
    }

    public function bindTelegram(int $telegramId, ?string $username): void
    {
        $this->telegram_id = $telegramId;
        $this->telegram_username = $username;
        $this->telegram_bind_code = null;
        $this->telegram_bind_code_expires_at = null;
        $this->save();
    }

    public function unbindTelegram(): void
    {
        $this->telegram_id = null;
        $this->telegram_username = null;
        $this->save();
    }

    public function routeNotificationForTelegram(): ?int
    {
        return $this->telegram_id;
    }

    /**
     * Admins who've bound Telegram — the recipients for admin-facing bot
     * notifications (new paid order, new ticket). No separate "admin chat
     * id" setting needed; whoever binds via the admin profile page gets them.
     */
    public static function telegramBoundAdmins(): Collection
    {
        return static::where('is_admin', true)->whereNotNull('telegram_id')->get();
    }

    /**
     * Admins of the CURRENT site who should hear about new tickets and paid
     * orders — everyone, not just the Telegram-bound. The notifications pick
     * the channel per recipient (Telegram if linked, e-mail otherwise).
     *
     * Runs on the current connection, so in a 分站 this is that agent's own
     * admins: a customer writing in reaches their shopkeeper, not the platform.
     */
    public static function adminsToNotify(): Collection
    {
        return static::where('is_admin', true)->get();
    }
}
