<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BalanceTransaction extends Model
{
    public const TYPE_ADJUST_ADMIN = 'adjust_admin';
    public const TYPE_GIFT_CARD = 'gift_card';
    public const TYPE_COMMISSION = 'commission';
    public const TYPE_UPGRADE_CREDIT = 'upgrade_credit';
    public const TYPE_ORDER_PAYMENT = 'order_payment';
    public const TYPE_AGENT_GENERATE = 'agent_generate';
    public const TYPE_AGENT_ORDER = 'agent_order';

    protected $fillable = ['user_id', 'amount', 'balance_after', 'type', 'note', 'admin_id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'balance_after' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function typeLabel(): string
    {
        return [
            self::TYPE_ADJUST_ADMIN => '管理员调整',
            self::TYPE_GIFT_CARD => '礼品卡充值',
            self::TYPE_COMMISSION => '佣金划转',
            self::TYPE_UPGRADE_CREDIT => '升级/换套餐折抵',
            self::TYPE_ORDER_PAYMENT => '订单支付',
        ][$this->type] ?? $this->type;
    }
}
