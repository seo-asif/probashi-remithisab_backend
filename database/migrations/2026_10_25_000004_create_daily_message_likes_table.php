<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('daily_message_likes', function (Blueprint $table): void {
   $table->id();
   $table->foreignId('daily_message_id')->constrained('daily_messages')->cascadeOnDelete();
   $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
   $table->timestamp('created_at')->useCurrent();
   $table->unique(['daily_message_id','user_id']);
   $table->index(['user_id','daily_message_id']);
  });
 }
 public function down(): void { Schema::dropIfExists('daily_message_likes'); }
};