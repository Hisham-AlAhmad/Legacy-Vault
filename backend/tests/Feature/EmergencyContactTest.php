<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeClientVault;
use Tests\TestCase;

/** Emergency contact — Phase 2a (EC-1, EC-4, docs/07_Database_Design.md §emergency_contacts). */
class EmergencyContactTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery staple 9!';

    private FakeClientVault $vault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->vault = new FakeClientVault();
    }

    private function createRecipient(User $user, string $email): int
    {
        return $this->postJson('/api/v1/recipients', [
            'name_enc' => $this->vault->sealField('Recipient', 'recipient.name', $user->id),
            'relationship_enc' => $this->vault->sealField('Friend', 'recipient.relationship', $user->id),
            'email' => $email,
            'phone' => null,
            'preferred_methods' => ['email'],
        ])->assertCreated()->json('recipient.id');
    }

    public function test_set_replace_and_link_to_a_recipient(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->getJson('/api/v1/emergency-contact')->assertOk()->assertJsonPath('emergency_contact', null);

        $contact = $this->postJson('/api/v1/emergency-contact', [
            'name_enc' => $this->vault->sealField('Nadia (neighbour)', 'emergency_contact.name', $user->id),
            'email' => 'nadia@example.com',
            'phone' => '+962781112223',
        ])->assertOk()->json('emergency_contact');

        $this->assertSame('Nadia (neighbour)', $this->vault->openField($contact['name_enc'], 'emergency_contact.name', $user->id));
        $this->assertNull($contact['linked_recipient_id']);

        // Replace with someone who is also a recipient (EC-1: the roles may overlap).
        $first = $this->createRecipient($user, 'sami@example.com');
        $second = $this->createRecipient($user, 'rana@example.com');

        foreach ([$first, $second] as $linked) {
            $this->postJson('/api/v1/emergency-contact', [
                'name_enc' => $this->vault->sealField('Linked', 'emergency_contact.name', $user->id),
                'email' => 'linked@example.com',
                'recipient_id' => $linked,
            ])->assertOk()->assertJsonPath('emergency_contact.linked_recipient_id', $linked);
        }

        // Still exactly one contact, and only the latest linked recipient carries the flag.
        $this->assertDatabaseCount('emergency_contacts', 1);
        $flags = collect($this->getJson('/api/v1/recipients')->json('recipients'))->pluck('is_emergency_contact', 'id');
        $this->assertFalse($flags[$first]);
        $this->assertTrue($flags[$second]);
        $this->getJson('/api/v1/emergency-contact')->assertJsonPath('emergency_contact.linked_recipient_id', $second);

        $reasons = DB::table('audit_logs')->where('event_type', 'emergency_contact.set')->pluck('metadata')
            ->map(fn (string $json): string => json_decode($json, true)['reason']);
        $this->assertSame(['created', 'replaced', 'replaced'], $reasons->all());
    }

    public function test_cannot_link_another_users_recipient(): void
    {
        $owner = User::factory()->create();
        $foreignId = $this->actingAs($owner)->createRecipient($owner, 'owner-recipient@example.com');

        $other = User::factory()->create();
        $this->actingAs($other)->postJson('/api/v1/emergency-contact', [
            'name_enc' => $this->vault->sealField('X', 'emergency_contact.name', $other->id),
            'email' => 'x@example.com',
            'recipient_id' => $foreignId,
        ])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['recipient_id']]]);

        $this->assertDatabaseCount('emergency_contacts', 0);
        $this->assertDatabaseHas('recipients', ['id' => $foreignId, 'is_emergency_contact' => false]);
    }

    public function test_email_is_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/emergency-contact', [
            'name_enc' => $this->vault->sealField('Nadia', 'emergency_contact.name', $user->id),
            'email' => null,
        ])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['email']]]);
    }

    public function test_remove_requires_password(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->deleteJson('/api/v1/emergency-contact', ['password' => self::PASSWORD])
            ->assertNotFound()->assertJsonPath('error.code', 'EMERGENCY_CONTACT_NOT_FOUND');

        $linked = $this->createRecipient($user, 'sami@example.com');
        $this->postJson('/api/v1/emergency-contact', [
            'name_enc' => $this->vault->sealField('Sami', 'emergency_contact.name', $user->id),
            'email' => 'sami@example.com',
            'recipient_id' => $linked,
        ])->assertOk();

        $this->deleteJson('/api/v1/emergency-contact', ['password' => 'wrong password entirely'])->assertStatus(401);
        $this->assertDatabaseCount('emergency_contacts', 1);

        $this->deleteJson('/api/v1/emergency-contact', ['password' => self::PASSWORD])->assertOk();

        $this->assertDatabaseCount('emergency_contacts', 0);
        $this->assertDatabaseHas('recipients', ['id' => $linked, 'is_emergency_contact' => false]);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'event_type' => 'emergency_contact.removed']);
    }
}
