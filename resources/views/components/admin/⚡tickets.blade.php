<?php

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Notifications\TicketReplied;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin', ['title' => '工单管理'])] class extends Component
{
    use WithPagination;

    public ?int $selectedTicketId = null;
    public string $reply = '';
    public string $search = '';
    public string $statusFilter = ''; // '' | open | closed

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function setLevel(string $level): void
    {
        if (! $this->selectedTicketId || ! in_array($level, [Ticket::LEVEL_LOW, Ticket::LEVEL_MEDIUM, Ticket::LEVEL_HIGH], true)) {
            return;
        }

        Ticket::whereKey($this->selectedTicketId)->update(['level' => $level]);
    }

    public function select(int $ticketId): void
    {
        $this->selectedTicketId = $ticketId;
    }

    public function backToList(): void
    {
        $this->selectedTicketId = null;
    }

    public function sendReply(): void
    {
        $this->validate(['reply' => ['required', 'string', 'max:5000']]);

        $message = TicketMessage::create([
            'ticket_id' => $this->selectedTicketId,
            'user_id' => auth()->id(),
            'content' => $this->reply,
        ]);

        $message->load('ticket.user');

        // A reply means the conversation is active again — reopen a closed
        // ticket so it doesn't get lost (mirrors the customer-reply behaviour).
        if (! $message->ticket->isOpen()) {
            $message->ticket->update(['status' => Ticket::STATUS_OPEN]);
        }

        $message->ticket->user->notify(new TicketReplied($message));

        $this->reply = '';
    }

    public function toggleStatus(): void
    {
        $ticket = Ticket::findOrFail($this->selectedTicketId);
        $ticket->update(['status' => $ticket->isOpen() ? Ticket::STATUS_CLOSED : Ticket::STATUS_OPEN]);
    }

    public function with(): array
    {
        $query = Ticket::with('user')->latest();

        if ($this->search !== '') {
            $query->where(function ($q) {
                $q->where('subject', 'like', "%{$this->search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$this->search}%"));
            });
        }

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        return [
            'tickets' => $query->paginate(20),
            'selectedTicket' => $this->selectedTicketId
                ? Ticket::with(['user', 'messages.user'])->find($this->selectedTicketId)
                : null,
        ];
    }
};
?>

<div class="space-y-6">
    <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">工单管理</h1>

    @if ($selectedTicket)
        <button wire:click="backToList" class="text-xs text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100">&larr; 返回列表</button>

        <div class="border border-gray-200 dark:border-gray-800 rounded-xl bg-white dark:bg-gray-900">
            <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
                <div>
                    <h2 class="font-medium text-gray-900 dark:text-gray-100">{{ $selectedTicket->subject }}</h2>
                    <div class="flex items-center gap-2 mt-1">
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $selectedTicket->user->email }}</p>
                        <span class="text-xs text-gray-400 dark:text-gray-500">· 级别</span>
                        <select wire:change="setLevel($event.target.value)" class="text-xs border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-2 py-0.5">
                            <option value="low" @selected($selectedTicket->level === 'low')>低</option>
                            <option value="medium" @selected($selectedTicket->level === 'medium')>中</option>
                            <option value="high" @selected($selectedTicket->level === 'high')>高</option>
                        </select>
                    </div>
                </div>
                <button wire:click="toggleStatus" class="text-xs font-medium rounded-full px-2 py-1 {{ $selectedTicket->isOpen() ? 'bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400' : 'bg-gray-100 dark:bg-gray-800 text-gray-500' }}">
                    {{ $selectedTicket->isOpen() ? '处理中（点击关闭）' : '已关闭（点击重开）' }}
                </button>
            </div>

            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($selectedTicket->messages as $msg)
                    <div class="px-5 py-4">
                        <div class="text-xs text-gray-400 dark:text-gray-500 mb-1">
                            {{ $msg->user->is_admin ? '客服' : $msg->user->email }} · {{ $msg->created_at->format('Y-m-d H:i') }}
                        </div>
                        <div class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-line">{{ $msg->content }}</div>
                    </div>
                @endforeach
            </div>

            <div class="px-5 py-4 border-t border-gray-100 dark:border-gray-800 space-y-3">
                <textarea wire:model="reply" rows="3" maxlength="5000" placeholder="回复客户..." class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm"></textarea>
                @error('reply') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                <button wire:click="sendReply" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">回复</button>
            </div>
        </div>
    @else
        <div class="flex flex-wrap items-center gap-2">
            <input
                type="text"
                wire:model.live.debounce.400ms="search"
                placeholder="搜索主题或客户邮箱..."
                class="flex-1 min-w-48 max-w-xs border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm"
            >
            <select wire:model.live="statusFilter" class="border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                <option value="">全部状态</option>
                <option value="open">处理中</option>
                <option value="closed">已关闭</option>
            </select>
        </div>

        <table class="w-full text-sm bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl overflow-hidden">
            <thead class="bg-gray-50 dark:bg-gray-800/60 text-gray-500 dark:text-gray-400 text-xs">
                <tr>
                    <th class="text-left px-4 py-2">状态</th>
                    <th class="text-left px-4 py-2">级别</th>
                    <th class="text-left px-4 py-2">主题</th>
                    <th class="text-left px-4 py-2">客户</th>
                    <th class="text-left px-4 py-2">提交时间</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tickets as $ticket)
                    <tr wire:click="select({{ $ticket->id }})" class="border-t border-gray-100 dark:border-gray-800 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/60">
                        <td class="px-4 py-2">
                            @if ($ticket->isOpen())
                                <span class="text-xs bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400 rounded-full px-2 py-1">处理中</span>
                            @else
                                <span class="text-xs bg-gray-100 dark:bg-gray-800 text-gray-500 rounded-full px-2 py-1">已关闭</span>
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            @php
                                $levelStyles = match ($ticket->level) {
                                    'high' => 'bg-red-50 dark:bg-red-500/10 text-red-700 dark:text-red-400',
                                    'medium' => 'bg-amber-50 dark:bg-amber-500/10 text-amber-700 dark:text-amber-400',
                                    default => 'bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400',
                                };
                            @endphp
                            <span class="text-xs rounded-full px-2 py-1 {{ $levelStyles }}">{{ $ticket->levelLabel() }}</span>
                        </td>
                        <td class="px-4 py-2 text-gray-700 dark:text-gray-300">{{ $ticket->subject }}</td>
                        <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $ticket->user->email }}</td>
                        <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $ticket->created_at->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-gray-400 dark:text-gray-500">还没有任何工单</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        {{ $tickets->links() }}
    @endif
</div>
