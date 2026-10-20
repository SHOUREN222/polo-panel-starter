<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    use \Stancl\Tenancy\Database\Concerns\CentralConnection; // 平台共享，分站从 central 读
    public const KEY_VERIFY_EMAIL = 'verify_email';
    public const KEY_SITE_NOTICE = 'site_notice';
    public const KEY_EXPIRE_REMINDER = 'expire_reminder';
    public const KEY_TRAFFIC_REMINDER = 'traffic_reminder';
    public const KEY_MAGIC_LOGIN = 'magic_login';

    public const LABELS = [
        self::KEY_VERIFY_EMAIL => '邮箱验证',
        self::KEY_SITE_NOTICE => '站点通知',
        self::KEY_EXPIRE_REMINDER => '到期提醒',
        self::KEY_TRAFFIC_REMINDER => '流量提醒',
        self::KEY_MAGIC_LOGIN => '邮件登录',
    ];

    protected $fillable = ['key', 'subject', 'content'];
}
