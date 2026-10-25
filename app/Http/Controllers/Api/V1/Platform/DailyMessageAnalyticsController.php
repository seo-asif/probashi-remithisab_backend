<?php
namespace App\Http\Controllers\Api\V1\Platform;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\DailyMessage;
use App\Support\DailyMessageCategories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
final class DailyMessageAnalyticsController extends Controller {
 public function summary(): JsonResponse {
  return ApiResponse::success([
   'total_likes'=>DB::table('daily_message_likes')->count(),
   'liked_messages'=>DB::table('daily_message_likes')->distinct()->count('daily_message_id'),
   'unique_likers'=>DB::table('daily_message_likes')->distinct()->count('user_id'),
   'total_messages'=>DailyMessage::count(),
  ]);
 }
 public function topLiked(Request $request): JsonResponse {
  $filters=$request->validate(['limit'=>['sometimes','integer','min:1','max:100'],'category'=>['sometimes',Rule::in(DailyMessageCategories::codes())]]);
  $query=DailyMessage::query()->leftJoin('daily_message_likes as likes','likes.daily_message_id','=','daily_messages.id')
   ->select('daily_messages.id','daily_messages.uuid','daily_messages.code','daily_messages.category','daily_messages.status','daily_messages.message_bn','daily_messages.message_en')
   ->selectRaw('COUNT(likes.id) as likes_count')
   ->groupBy('daily_messages.id','daily_messages.uuid','daily_messages.code','daily_messages.category','daily_messages.status','daily_messages.message_bn','daily_messages.message_en');
  if(isset($filters['category'])) $query->where('daily_messages.category',$filters['category']);
  $messages = $query->orderByDesc('likes_count')->orderBy('daily_messages.code')
   ->limit($filters['limit']??20)->get()->map(function (DailyMessage $message): array {
    return [
     'id' => $message->uuid,
     'uuid' => $message->uuid,
     'code' => $message->code,
     'category' => $message->category,
     'status' => $message->status,
     'message_bn' => $message->message_bn,
     'message_en' => $message->message_en,
     'likes_count' => (int) $message->likes_count,
    ];
   });
  return ApiResponse::success($messages);
 }
 public function categoryStats(): JsonResponse {
  $counts=DailyMessage::query()->leftJoin('daily_message_likes as likes','likes.daily_message_id','=','daily_messages.id')
   ->select('daily_messages.category')->selectRaw('COUNT(DISTINCT daily_messages.id) as messages_count')
   ->selectRaw('COUNT(likes.id) as likes_count')->groupBy('daily_messages.category')->get()
   ->keyBy(fn($row)=>$row->category??'__uncategorized__');
  $categories=[];
  foreach(DailyMessageCategories::LABELS as $code=>$labels) {
   $row=$counts->get($code);
   $categories[]=['category'=>$code,'label_en'=>$labels['en'],'label_bn'=>$labels['bn'],
    'messages_count'=>(int)($row?->messages_count??0),'likes_count'=>(int)($row?->likes_count??0)];
  }
  $unknown=$counts->get('__uncategorized__');
  if($unknown) $categories[]=['category'=>null,'label_en'=>'Uncategorized','label_bn'=>'শ্রেণিবিহীন',
   'messages_count'=>(int)$unknown->messages_count,'likes_count'=>(int)$unknown->likes_count];
  usort($categories,fn($a,$b)=>$b['likes_count']<=>$a['likes_count'] ?: strcmp((string)$a['category'],(string)$b['category']));
  return ApiResponse::success(['categories'=>$categories]);
 }
}