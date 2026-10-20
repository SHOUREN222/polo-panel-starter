<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketReplied;
use App\Services\WebAbuseLimiter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class TicketsTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_create_a_ticket(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('tickets')
            ->call('startNewTicket')
            ->set('subject', '无法连接节点')
            ->set('level', 'high')
            ->set('message', '购买后一直显示开通中')
            ->call('createTicket');

        $this->assertDatabaseHas('tickets', ['subject' => '无法连接节点', 'user_id' => $user->id, 'status' => 'open', 'level' => 'high']);
        $this->assertDatabaseHas('ticket_messages', ['content' => '购买后一直显示开通中']);
    }

    public function test_ticket_defaults_to_low_level_when_not_set(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('tickets')
            ->call('startNewTicket')
            ->set('subject', '一般问题')
            ->set('message', '想咨询一下')
            ->call('createTicket');

        $this->assertDatabaseHas('tickets', ['subject' => '一般问题', 'level' => 'low']);
    }

    public function test_ticket_content_is_limited_to_5000_characters(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('tickets')
            ->call('startNewTicket')
            ->set('subject', 'oversized')
            ->set('message', str_repeat('a', 5001))
            ->call('createTicket')
            ->assertHasErrors(['message' => 'max']);

        $this->assertDatabaseMissing('tickets', ['subject' => 'oversized']);
    }

    public function test_customer_ticket_creation_is_rate_limited_before_writes_and_notifications(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $limiter = app(WebAbuseLimiter::class);

        for ($i = 0; $i < WebAbuseLimiter::TICKET_CREATE_USER_MAX; $i++) {
            $limiter->recordTicketCreate($user->id, '127.0.0.1');
        }

        Livewire::actingAs($user)->test('tickets')
            ->call('startNewTicket')
            ->set('subject', 'rate-limited')
            ->set('message', 'should not be stored')
            ->call('createTicket')
            ->assertHasErrors(['message']);

        $this->assertDatabaseMissing('tickets', ['subject' => 'rate-limited']);
        Notification::assertNothingSent();
    }

    public function test_customer_can_reply_and_reopens_closed_ticket(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::create(['user_id' => $user->id, 'subject' => 'x', 'status' => 'closed']);

        Livewire::actingAs($user)->test('tickets')
            ->call('select', $ticket->id)
            ->set('reply', '还是不行')
            ->call('sendReply');

        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertDatabaseHas('ticket_messages', ['content' => '还是不行', 'ticket_id' => $ticket->id]);
    }

    public function test_customer_reply_is_limited_to_5000_characters(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::create(['user_id' => $user->id, 'subject' => 'x', 'status' => 'open']);

        Livewire::actingAs($user)->test('tickets')
            ->call('select', $ticket->id)
            ->set('reply', str_repeat('a', 5001))
            ->call('sendReply')
            ->assertHasErrors(['reply' => 'max']);

        $this->assertSame(0, $ticket->messages()->count());
    }

    public function test_customer_replies_are_rate_limited_before_database_writes(): void
    {
        $user = User::factory()->create();
        $ticket = Ticket::create(['user_id' => $user->id, 'subject' => 'x', 'status' => 'open']);
        $limiter = app(WebAbuseLimiter::class);

        for ($i = 0; $i < WebAbuseLimiter::TICKET_REPLY_USER_MAX; $i++) {
            $limiter->recordTicketReply($user->id, '127.0.0.1');
        }

        Livewire::actingAs($user)->test('tickets')
            ->call('select', $ticket->id)
            ->set('reply', 'rate-limited reply')
            ->call('sendReply')
            ->assertHasErrors(['reply']);

        $this->assertSame(0, $ticket->messages()->count());
    }

    public function test_customer_cannot_view_another_users_ticket(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $ticket = Ticket::create(['user_id' => $owner->id, 'subject' => 'private', 'status' => 'open']);

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($intruder)->test('tickets')
            ->call('select', $ticket->id);
    }

    public function test_customer_cannot_mutate_selected_ticket_id(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $ticket = Ticket::create(['user_id' => $owner->id, 'subject' => 'private', 'status' => 'open']);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($intruder)->test('tickets')
            ->set('selectedTicketId', $ticket->id);
    }

    public function test_customer_ticket_render_is_scoped_to_the_owner(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $ticket = Ticket::create(['user_id' => $owner->id, 'subject' => 'private-subject', 'status' => 'open']);
        $ticket->messages()->create([
            'user_id' => $owner->id,
            'content' => 'private-message-content',
        ]);

        $component = Livewire::actingAs($intruder)->test('tickets', [
            'selectedTicketId' => $ticket->id,
        ]);

        $this->assertNull($component->viewData('selectedTicket'));
        $component
            ->assertDontSee('private-subject')
            ->assertDontSee('private-message-content');
    }

    public function test_admin_can_reply_and_close_ticket(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $ticket = Ticket::create(['user_id' => $user->id, 'subject' => 'x', 'status' => 'open']);

        Livewire::actingAs($admin)->test('admin.tickets')
            ->call('select', $ticket->id)
            ->set('reply', '已经修复，请重试')
            ->call('sendReply')
            ->call('toggleStatus');

        $this->assertSame('closed', $ticket->fresh()->status);
        $this->assertDatabaseHas('ticket_messages', ['content' => '已经修复，请重试', 'user_id' => $admin->id]);
    }

    public function test_admin_reply_reopens_a_closed_ticket(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $ticket = Ticket::create(['user_id' => $user->id, 'subject' => 'x', 'status' => 'closed']);

        Livewire::actingAs($admin)->test('admin.tickets')
            ->call('select', $ticket->id)
            ->set('reply', '再帮你看看')
            ->call('sendReply');

        $this->assertSame('open', $ticket->fresh()->status);
    }

    public function test_admin_reply_is_limited_to_5000_characters(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $ticket = Ticket::create(['user_id' => $user->id, 'subject' => 'x', 'status' => 'open']);

        Livewire::actingAs($admin)->test('admin.tickets')
            ->call('select', $ticket->id)
            ->set('reply', str_repeat('a', 5001))
            ->call('sendReply')
            ->assertHasErrors(['reply' => 'max']);

        $this->assertSame(0, $ticket->messages()->count());
    }

    public function test_admin_can_change_ticket_level(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $ticket = Ticket::create(['user_id' => User::factory()->create()->id, 'subject' => 'x', 'status' => 'open', 'level' => 'low']);

        Livewire::actingAs($admin)->test('admin.tickets')
            ->call('select', $ticket->id)
            ->call('setLevel', 'high');

        $this->assertSame('high', $ticket->fresh()->level);
    }

    public function test_admin_status_filter_narrows_the_list(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $u = User::factory()->create();
        $open = Ticket::create(['user_id' => $u->id, 'subject' => 'OPENONE', 'status' => 'open']);
        $closed = Ticket::create(['user_id' => $u->id, 'subject' => 'CLOSEDONE', 'status' => 'closed']);

        Livewire::actingAs($admin)->test('admin.tickets')
            ->set('statusFilter', 'closed')
            ->assertSee('CLOSEDONE')
            ->assertDontSee('OPENONE');
    }

    public function test_admin_reply_notifies_ticket_owner_by_email(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create();
        $ticket = Ticket::create(['user_id' => $user->id, 'subject' => 'x', 'status' => 'open']);

        Livewire::actingAs($admin)->test('admin.tickets')
            ->call('select', $ticket->id)
            ->set('reply', '已经修复，请重试')
            ->call('sendReply');

        Notification::assertSentTo($user, TicketReplied::class);
    }

    public function test_customer_reply_does_not_notify_anyone(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        $ticket = Ticket::create(['user_id' => $user->id, 'subject' => 'x', 'status' => 'open']);

        Livewire::actingAs($user)->test('tickets')
            ->call('select', $ticket->id)
            ->set('reply', '还是不行')
            ->call('sendReply');

        Notification::assertNothingSent();
    }

    public function test_new_query_param_opens_create_form_directly(): void
    {
        $user = User::factory()->create();

        Livewire::withQueryParams(['new' => '1'])->actingAs($user)->test('tickets')
            ->assertSet('showCreateForm', true);
    }

    public function test_without_new_query_param_create_form_stays_closed(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test('tickets')
            ->assertSet('showCreateForm', false);
    }
}
