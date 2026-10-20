<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\User;
use App\Notifications\OnipBalanceLowAlert;
use App\Services\OnipProvisioningService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

#[Signature('onip:balance-check')]
#[Description('Alert platform admins (Telegram) when the onip account balance falls below the configured threshold, before static-ISP provisioning/renewals start failing.')]
class CheckOnipBalance extends Command
{
    public function handle(OnipProvisioningService $onip): int
    {
        // Only relevant when onip is the active provisioner and it's configured.
        if (Setting::get('provisioner_driver', 'stub') !== 'onip' || ! $onip->isConfigured()) {
            return self::SUCCESS;
        }

        $threshold = (float) Setting::get('onip_low_balance_threshold', 100);

        try {
            $account = $onip->getAccount();
        } catch (\Throwable $e) {
            Log::warning('onip:balance-check could not read the account', ['error' => $e->getMessage()]);

            return self::SUCCESS;
        }

        $balance = (float) ($account['balance'] ?? 0);
        $currency = (string) ($account['currency'] ?? 'CNY');

        $this->info("onip balance = {$balance} {$currency} (threshold {$threshold})");

        if ($balance >= $threshold) {
            return self::SUCCESS;
        }

        $admins = User::telegramBoundAdmins();
        if ($admins->isNotEmpty()) {
            Notification::send($admins, new OnipBalanceLowAlert(
                balance: $balance,
                currency: $currency,
                threshold: $threshold,
            ));
        }

        return self::SUCCESS;
    }
}
