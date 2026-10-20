<?php

use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Notifications\NewTicketCreated;
use App\Services\WebAbuseLimiter;
use Illuminate\Support\Facades\Notification;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('layouts.app', ['title' => '工单'])] class extends Component
{
    #[Locked]
    public ?int $selectedTicketId = null;
    public bool $showCreateForm = false;

    public string $subject = '';
    public string $level = Ticket::LEVEL_LOW;
    public string $message = '';
    public string $reply = '';

    public string $error = '';

    public function mount(): void
    {
        abort_unless(Setting::getBool('ticket_system_enabled', true), 404);

        if (request()->query('new')) {
            $this->showCreateForm = true;
        }
    }

    public function select(int $ticketId): void
    {
        $this->error = '';
        $ticket = Ticket::where('id', $ticketId)->where('user_id', auth()->id())->firstOrFail();
        $this->selectedTicketId = $ticket->id;
        $this->showCreateForm = false;
    }

    public function backToList(): void
    {
        $this->selectedTicketId = null;
    }

    public function startNewTicket(): void
    {
        $this->subject = '';
        $this->level = Ticket::LEVEL_LOW;
        $this->message = '';
        $this->showCreateForm = true;
        $this->selectedTicketId = null;
    }

    public function createTicket(WebAbuseLimiter $limiter): void
    {
        $this->validate([
            'subject' => ['required', 'string', 'max:255'],
            'level' => ['required', 'in:low,medium,high'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $userId = (int) auth()->id();
        $ip = request()->ip();
        $retryAfter = $limiter->ticketCreateRetryAfter($userId, $ip);

        if ($retryAfter > 0) {
            $minutes = max(1, (int) ceil($retryAfter / 60));
            $this->addError('message', "工单提交过于频繁，请 {$minutes} 分钟后再试");

            return;
        }

        // Reserve both buckets before any database write or notification.
        $limiter->recordTicketCreate($userId, $ip);

        $ticket = Ticket::create([
            'user_id' => $userId,
            'subject' => $this->subject,
            'level' => $this->level,
            'status' => Ticket::STATUS_OPEN,
        ]);

        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $userId,
            'content' => $this->message,
        ]);

        Notification::send(User::adminsToNotify(), new NewTicketCreated($ticket));

        $this->showCreateForm = false;
        $this->selectedTicketId = $ticket->id;
    }

    public function sendReply(WebAbuseLimiter $limiter): void
    {
        $this->validate(['reply' => ['required', 'string', 'max:5000']]);

        $userId = (int) auth()->id();
        $ip = request()->ip();
        $retryAfter = $limiter->ticketReplyRetryAfter($userId, $ip);

        if ($retryAfter > 0) {
            $minutes = max(1, (int) ceil($retryAfter / 60));
            $this->addError('reply', "回复过于频繁，请 {$minutes} 分钟后再试");

            return;
        }

        $ticket = Ticket::where('id', $this->selectedTicketId)->where('user_id', auth()->id())->firstOrFail();

        $limiter->recordTicketReply($userId, $ip);

        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $userId,
            'content' => $this->reply,
        ]);

        if (! $ticket->isOpen()) {
            $ticket->update(['status' => Ticket::STATUS_OPEN]);
        }

        $this->reply = '';
    }

    public function closeTicket(): void
    {
        Ticket::where('id', $this->selectedTicketId)->where('user_id', auth()->id())->firstOrFail()
            ->update(['status' => Ticket::STATUS_CLOSED]);
    }

    public function with(): array
    {
        $tickets = auth()->user()->tickets()->with('latestMessage')->latest()->get();

        return [
            'tickets' => $tickets,
            'selectedTicket' => $this->selectedTicketId
                ? auth()->user()->tickets()->with('messages.user')->find($this->selectedTicketId)
                : null,
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">我的工单</h1>
        @unless ($showCreateForm || $selectedTicket)
            <button wire:click="startNewTicket" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-3 py-1.5">新的工单</button>
        @endunless
    </div>

    @if ($showCreateForm)
        <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 space-y-4">
            <h2 class="font-medium text-gray-900 dark:text-gray-100">新建工单</h2>
            <div>
                <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">主题</label>
                <input type="text" wire:model="subject" maxlength="255" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                @error('subject') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">工单级别</label>
                <div class="flex gap-2">
                    @foreach (['low' => '低', 'medium' => '中', 'high' => '高'] as $value => $label)
                        <button
                            type="button"
                            wire:click="$set('level', '{{ $value }}')"
                            class="text-sm rounded-lg px-4 py-1.5 border {{ $level === $value ? 'bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 border-gray-900 dark:border-gray-100' : 'border-gray-300 dark:border-gray-700 text-gray-600 dark:text-gray-400' }}"
                        >{{ $label }}</button>
                    @endforeach
                </div>
                @error('level') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">问题描述</label>
                <textarea wire:model="message" rows="4" maxlength="5000" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm"></textarea>
                @error('message') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="flex gap-2">
                <button wire:click="createTicket" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">提交</button>
                <button wire:click="$set('showCreateForm', false)" class="text-sm border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-4 py-2">取消</button>
            </div>
        </div>
    @elseif ($selectedTicket)
        <div class="space-y-4">
            <button wire:click="backToList" class="text-xs text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100">&larr; 返回列表</button>

            <div class="border border-gray-200 dark:border-gray-800 rounded-xl bg-white dark:bg-gray-900">
                <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
                    <div>
                        <h2 class="font-medium text-gray-900 dark:text-gray-100">{{ $selectedTicket->subject }}</h2>
                        <span class="text-xs text-gray-400 dark:text-gray-500">级别：{{ $selectedTicket->levelLabel() }}</span>
                    </div>
                    @if ($selectedTicket->isOpen())
                        <span class="text-xs bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400 rounded-full px-2 py-1">处理中</span>
                    @else
                        <span class="text-xs bg-gray-100 dark:bg-gray-800 text-gray-500 rounded-full px-2 py-1">已关闭</span>
                    @endif
                </div>

                <div class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($selectedTicket->messages as $msg)
                        <div class="px-5 py-4">
                            <div class="text-xs text-gray-400 dark:text-gray-500 mb-1">
                                {{ $msg->user->is_admin ? '客服' : '我' }} · {{ $msg->created_at->format('Y-m-d H:i') }}
                            </div>
                            <div class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-line">{{ $msg->content }}</div>
                        </div>
                    @endforeach
                </div>

                @if ($selectedTicket->isOpen())
                    <div class="px-5 py-4 border-t border-gray-100 dark:border-gray-800 space-y-3">
                        <textarea wire:model="reply" rows="3" maxlength="5000" placeholder="回复..." class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm"></textarea>
                        @error('reply') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        <div class="flex gap-2">
                            <button wire:click="sendReply" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">回复</button>
                            <button wire:click="closeTicket" class="text-sm border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-4 py-2">关闭工单</button>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @else
        <div class="border border-gray-200 dark:border-gray-800 rounded-xl bg-white dark:bg-gray-900 overflow-hidden">
            <h2 class="text-sm font-medium text-gray-500 dark:text-gray-400 px-5 py-3 border-b border-gray-100 dark:border-gray-800">工单历史</h2>

            @if ($tickets->count())
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800/60 text-gray-500 dark:text-gray-400 text-xs">
                        <tr>
                            <th class="text-left px-4 py-2">主题</th>
                            <th class="text-left px-4 py-2">工单级别</th>
                            <th class="text-left px-4 py-2">工单状态</th>
                            <th class="text-left px-4 py-2">创建时间</th>
                            <th class="text-left px-4 py-2">最后回复时间</th>
                            <th class="text-left px-4 py-2">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tickets as $ticket)
                            <tr class="border-t border-gray-100 dark:border-gray-800">
                                <td class="px-4 py-3 text-gray-800 dark:text-gray-200">{{ $ticket->subject }}</td>
                                <td class="px-4 py-3">
                                    @php
                                        $levelStyles = match ($ticket->level) {
                                            'high' => 'bg-red-50 dark:bg-red-500/10 text-red-700 dark:text-red-400',
                                            'medium' => 'bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-400',
                                            default => 'bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400',
                                        };
                                    @endphp
                                    <span class="text-xs rounded-full px-2 py-1 {{ $levelStyles }}">{{ $ticket->levelLabel() }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    @if ($ticket->isOpen())
                                        <span class="text-xs bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400 rounded-full px-2 py-1">处理中</span>
                                    @else
                                        <span class="text-xs bg-gray-100 dark:bg-gray-800 text-gray-500 rounded-full px-2 py-1">已关闭</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ $ticket->created_at->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ $ticket->latestMessage?->created_at?->format('Y-m-d H:i') ?? '-' }}</td>
                                <td class="px-4 py-3">
                                    <button wire:click="select({{ $ticket->id }})" class="text-sm text-gray-900 dark:text-gray-100 underline">查看</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="flex flex-col items-center justify-center gap-3 py-16 text-gray-400 dark:text-gray-500">
                    @include('partials.icon', ['name' => 'inbox'])
                    <span class="text-sm">无数据</span>
                </div>
            @endif
        </div>
    @endif
</div>
