<?php
namespace Tests\Feature\DailyMessages;

use App\Enums\BusinessRole;
use App\Models\DailyMessage;
use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\MakesTenants;
use Tests\TestCase;

final class DailyMessageLikesTest extends TestCase {
 use MakesTenants,RefreshDatabase;
 private function member(): \App\Models\User {
  $business=$this->makeBusiness();
  $user=$this->makeMember($business,BusinessRole::ReadOnlyStaff);
  $user->forceFill(['status'=>'active','email_verified_at'=>now()])->save();
  return $user;
 }
 public function test_like_is_idempotent_and_unlike_removes_only_own_like(): void {
  $message=DailyMessage::create(['code'=>'LIKE001','message_bn'=>'Hello','status'=>'published','category'=>'family']);
  $first=$this->member(); $second=$this->member();
  Sanctum::actingAs($first);
  $url='/api/v1/daily-messages/'.$message->uuid.'/like';
  $this->putJson($url)->assertOk()->assertJsonPath('data.liked',true);
  $this->putJson($url)->assertOk();
  $this->assertDatabaseCount('daily_message_likes',1);
  Sanctum::actingAs($second); $this->putJson($url)->assertOk();
  $this->assertDatabaseCount('daily_message_likes',2);
  Sanctum::actingAs($first);
  $this->getJson('/api/v1/daily-messages/likes')->assertOk()->assertJsonPath('data.message_ids.0',$message->uuid);
  $this->deleteJson($url)->assertOk()->assertJsonPath('data.liked',false);
  $this->deleteJson($url)->assertOk();
  $this->assertDatabaseCount('daily_message_likes',1);
  $this->assertDatabaseHas('daily_message_likes',['user_id'=>$second->id,'daily_message_id'=>$message->id]);
 }
 public function test_draft_cannot_be_liked_and_guest_is_unauthorized(): void {
  $message=DailyMessage::create(['code'=>'LIKE002','message_bn'=>'Hidden','status'=>'draft']);
  $url='/api/v1/daily-messages/'.$message->uuid.'/like';
  $this->putJson($url)->assertUnauthorized();
  Sanctum::actingAs($this->member());
  $this->putJson($url)->assertNotFound();
  $this->assertDatabaseCount('daily_message_likes',0);
 }
 public function test_only_platform_admin_can_see_rankings_and_category_totals(): void {
  $message=DailyMessage::create(['code'=>'LIKE003','message_bn'=>'Family','status'=>'published','category'=>'family']);
  $user=$this->member(); Sanctum::actingAs($user);
  $this->putJson('/api/v1/daily-messages/'.$message->uuid.'/like')->assertOk();
  $this->getJson('/api/v1/platform/daily-messages/analytics')->assertForbidden();
  PlatformAdmin::create(['user_id'=>$user->id,'status'=>'active']);
  $this->getJson('/api/v1/platform/daily-messages/analytics')->assertOk()
   ->assertJsonPath('data.total_likes',1)->assertJsonPath('data.unique_likers',1);
  $this->getJson('/api/v1/platform/daily-messages/top-liked')->assertOk()
   ->assertJsonPath('data.0.code','LIKE003')->assertJsonPath('data.0.likes_count',1);
  $this->getJson('/api/v1/platform/daily-messages/category-stats')->assertOk()
   ->assertJsonPath('data.categories.0.category','family')->assertJsonPath('data.categories.0.likes_count',1);
 }
}
