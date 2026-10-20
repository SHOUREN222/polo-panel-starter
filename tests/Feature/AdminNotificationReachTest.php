<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\NewTicketCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * "New ticket" and "order paid" used to be Telegram-only, and only sent to
 * Telegram-bound admins — so an agent who hadn't linked an account heard
 * nothing at all while their customer sat waiting for a reply.
 */
class AdminNotificationReachTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenant()) {
            tenancy()->end();
        }
        foreach (Tenant::all() as $t) {
            $t->delete();
        }
        parent::tearDown();
    }

    public function test_an_agent_without_telegram_is_emailed_about_a_new_ticket(): void
    {
        $t = Tenant::create(['id' => 'notify']);

        [$agent, $ticket] = $t->run(function () {
            $agent = User::create(['name' => '代理', 'email' => 'agent@example.com', 'password' => 'x']);
            $agent->forceFill(['is_admin' => true])->save();      // deliberately no Telegram
            $customer = User::create(['name' => 'c', 'email' => 'c@example.com', 'password' => 'x']);
            $ticket = Ticket::create([
                'user_id' => $customer->id, 'subject' => '连不上', 'status' => Ticket::STATUS_OPEN,
            ]);

            return [$agent, $ticket];
        });

        $t->run(function () use ($ticket) {
            Notification::fake();
            Notification::send(User::adminsToNotify(), new NewTicketCreated($ticket));

            Notification::assertSentTo(
                User::where('email', 'agent@example.com')->first(),
                NewTicketCreated::class,
                fn ($n, $channels) => in_array('mail', $channels, true)
            );
        });
    }

    public function test_an_agent_with_telegram_still_gets_it_on_telegram(): void
    {
        $t = Tenant::create(['id' => 'notify2']);

        $ticket = $t->run(function () {
            $agent = User::create(['name' => '代理', 'email' => 'agent@example.com', 'password' => 'x']);
            $agent->forceFill(['is_admin' => true])->save();
            $agent->bindTelegram(4242, 'agent');
            $customer = User::create(['name' => 'c', 'email' => 'c@example.com', 'password' => 'x']);

            return Ticket::create([
                'user_id' => $customer->id, 'subject' => '续费问题', 'status' => Ticket::STATUS_OPEN,
            ]);
        });

        $t->run(function () use ($ticket) {
            Notification::fake();
            Notification::send(User::adminsToNotify(), new NewTicketCreated($ticket));

            Notification::assertSentTo(
                User::where('email', 'agent@example.com')->first(),
                NewTicketCreated::class,
                fn ($n, $channels) => ! in_array('mail', $channels, true)
            );
        });
    }

    public function test_a_tenant_ticket_does_not_notify_the_platform_owner(): void
    {
        // The agent's customers are the agent's business — the platform owner
        // shouldn't be pulled into every shop's support queue.
        //
        // Give the owner an id no tenant user will share: Notification::fake()
        // identifies a notifiable by class + primary key, and every 分站
        // numbers its users from 1, so a central #1 and a tenant #1 are
        // indistinguishable to the assertion.
        User::factory()->count(3)->create();
        $owner = User::factory()->create(['is_admin' => true]);
        $owner->bindTelegram(1111, 'owner');
        $this->assertGreaterThan(1, $owner->id);

        $t = Tenant::create(['id' => 'notify3']);
        $ticket = $t->run(function () {
            $agent = User::create(['name' => '代理', 'email' => 'agent@example.com', 'password' => 'x']);
            $agent->forceFill(['is_admin' => true])->save();
            $customer = User::create(['name' => 'c', 'email' => 'c@example.com', 'password' => 'x']);

            return Ticket::create([
                'user_id' => $customer->id, 'subject' => 'x', 'status' => Ticket::STATUS_OPEN,
            ]);
        });

        $t->run(function () use ($ticket) {
            Notification::fake();
            $recipients = User::adminsToNotify();

            // Only the 分站's own admin is in scope at all.
            $this->assertSame(['agent@example.com'], $recipients->pluck('email')->all());

            Notification::send($recipients, new NewTicketCreated($ticket));
        });

        Notification::assertNothingSentTo($owner);
    }
}
