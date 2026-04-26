<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\ChatRoom;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    public function index(Request $request)
    {
        $userId = Auth::guard('sanctum')->id();
        $sessionId = $request->header('X-Session-ID');

        $room = ChatRoom::where(function ($q) use ($userId, $sessionId) {
            if ($userId) {
                $q->where('user_id', $userId);
            } else {
                $q->where('session_id', $sessionId);
            }
        })->with(['messages.replyTo'])->first();

        if (! $room) {
            return response()->json(['status' => 'success', 'data' => [], 'is_locked' => false]);
        }

        $formattedChats = $room->messages->map(function ($msg) {
            return [
                'id' => $msg->id,
                'role' => $msg->sender_type, // 'user', 'ai', 'admin'
                'text' => $msg->message,
                'time' => $msg->created_at->format('H:i'),
                'instruction' => $msg->instruction,
                'replyTo' => $msg->replyTo ? [
                    'id' => $msg->replyTo->id,
                    'text' => $msg->replyTo->message,
                    'role' => $msg->replyTo->sender_type,
                ] : null,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $formattedChats,
            'is_locked' => $room->is_locked,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate(['message' => 'required|string']);
        $sessionId = $request->header('X-Session-ID') ?? Str::uuid()->toString();
        $userId = Auth::guard('sanctum')->id();

        // 1. Cari atau Buat Ruangan Chat
        $room = ChatRoom::firstOrCreate(
            ['user_id' => $userId, 'session_id' => $userId ? null : $sessionId],
            ['case_id' => 'UMUM-'.date('Ymd').'-'.strtoupper(Str::random(4))]
        );

        // 2. Simpan Pesan Warga
        $userMsg = ChatMessage::create([
            'chat_room_id' => $room->id,
            'sender_type' => 'user',
            'message' => $request->message,
            'reply_to_id' => $request->reply_to_id,
        ]);

        if ($room->is_locked) {
            return response()->json(['status' => 'success', 'is_locked' => true, 'session_id' => $sessionId]);
        }

        // 3. Panggil AI
        try {
            $response = Http::withoutVerifying()->post('https://api-nlp.safetalkai.my.id/predict', [
                'text' => $request->message,
            ]);

            if ($response->successful()) {
                $aiData = $response->json();
                $kategori = $aiData['predicted_label'] ?? 'Umum';
                $isEmergency = in_array($kategori, ['K1', 'K3']);

                // Jika bahaya meningkat, perbarui ID Kasus (cth: UMUM-xxx jadi K1-xxx)
                if ($kategori !== 'Umum' && $room->latest_category !== $kategori) {
                    $newCaseId = $kategori.'-'.date('Ymd').'-'.strtoupper(Str::random(4));
                    $room->update(['latest_category' => $kategori, 'case_id' => $newCaseId]);
                }

                if ($isEmergency) {
                    $room->update(['is_locked' => true]);
                }

                // 4. Simpan Pesan AI (AI membalas pesan warga tadi)
                ChatMessage::create([
                    'chat_room_id' => $room->id,
                    'sender_type' => 'ai',
                    'message' => $aiData['tanggapan_ai'] ?? '...',
                    'reply_to_id' => $userMsg->id,
                    'instruction' => $this->getInstruction($kategori),
                ]);

                return response()->json(['status' => 'success', 'session_id' => $sessionId, 'is_locked' => $isEmergency]);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'Service Down'], 500);
        }
    }

    private function getInstruction($kategori)
    {
        $map = [
            'K1' => '🚨 DARURAT: Kekerasan Fisik. Hubungi Polisi (110).',
            'K2' => '💡 ARAHAN: Kekerasan Psikis. Hubungi SEJIWA 119 Ext 8.',
            'K3' => '🚨 DARURAT: Kekerasan Seksual. Hubungi SAPA 129.',
            'K4' => '💡 ARAHAN: Penelantaran Ekonomi. Konsultasi LBH.',
            'K5' => 'ℹ️ INFO: Hubungi DP3A (WA: 0811-1341-129).',
        ];

        return $map[$kategori] ?? null;
    }
}
