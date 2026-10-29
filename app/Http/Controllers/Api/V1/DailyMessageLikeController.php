<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\DailyMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
final class DailyMessageLikeController extends Controller {
 public function index(Request $request): JsonResponse {
  $ids=DB::table('daily_message_likes as l')->join('daily_messages as m','m.id','=','l.daily_message_id')
   ->where('l.user_id',$request->user()->id)->where('m.status','published')->orderBy('m.id')->pluck('m.uuid');
  return ApiResponse::success(['message_ids'=>$ids]);
 }
 public function like(Request $request,string $id): JsonResponse {
  $message=DailyMessage::where('uuid',$id)->where('status','published')->firstOrFail();
  DB::table('daily_message_likes')->insertOrIgnore(['daily_message_id'=>$message->id,'user_id'=>$request->user()->id,'created_at'=>now()]);
  return ApiResponse::success(['id'=>$message->uuid,'liked'=>true]);
 }
 public function unlike(Request $request,string $id): JsonResponse {
  $message=DailyMessage::where('uuid',$id)->firstOrFail();
  DB::table('daily_message_likes')->where('daily_message_id',$message->id)->where('user_id',$request->user()->id)->delete();
  return ApiResponse::success(['id'=>$message->uuid,'liked'=>false]);
 }
}