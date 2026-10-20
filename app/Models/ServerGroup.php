<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerGroup extends Model
{
    // 节点分组是平台共享基础设施：套餐(Plan)和节点(Server)都钉在 central，
    // 分组必须跟着，否则在分站上下文里会去租户库找一张不存在的表。
    use \Stancl\Tenancy\Database\Concerns\CentralConnection;

    protected $fillable = ['name'];
}
